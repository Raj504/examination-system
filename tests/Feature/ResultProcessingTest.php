<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\ExaminationResult;
use App\Models\ResultRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExaminations;
use Tests\TestCase;

class ResultProcessingTest extends TestCase
{
    use BuildsExaminations, RefreshDatabase;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['exams.results.students_per_chunk' => 2]);
        $this->exam = $this->examinationInMarksEntry(4);
    }

    private function importMarks(array $rows): void
    {
        $this->post("/api/v1/examinations/{$this->exam->id}/mark-imports", [
            'file' => UploadedFile::fake()->createWithContent('m.csv', $this->csv($rows)),
        ], ['Accept' => 'application/json'])->assertStatus(202);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/examinations/{$this->exam->id}{$suffix}";
    }

    public function test_full_lifecycle_from_marks_to_published_results(): void
    {
        $this->importMarks([
            // S001: CS101 = 27/30*30 + 63/70*70 = 90 -> O ; CS102 = 75 -> A       => pass
            ['S001', 'CS101', 'MID', '27'], ['S001', 'CS101', 'END', '63'], ['S001', 'CS102', 'LAB', '75'],
            // S002: CS101 END 20 < min 28 -> F ; CS102 = 50 -> B                  => fail
            ['S002', 'CS101', 'MID', '30'], ['S002', 'CS101', 'END', '20'], ['S002', 'CS102', 'LAB', '50'],
            // S003: CS102 missing                                                  => withheld
            ['S003', 'CS101', 'MID', '15'], ['S003', 'CS101', 'END', '35'],
            // S004: absent everywhere in CS101 -> AB ; CS102 = 40 -> P             => fail
            ['S004', 'CS101', 'MID', 'AB'], ['S004', 'CS101', 'END', 'AB'], ['S004', 'CS102', 'LAB', '40'],
        ]);

        $this->getJson($this->url('/progress'))->assertOk()
            ->assertJsonPath('data.expected_marks', 12)
            ->assertJsonPath('data.missing_marks', 1);

        // Results are not visible before processing.
        $this->getJson($this->url('/results'))->assertStatus(409);

        $this->postJson($this->url('/lock-marks'))->assertOk()->assertJsonPath('data.status', 'marks_locked');
        $runId = $this->postJson($this->url('/result-runs'))->assertStatus(202)->json('data.id');

        $run = ResultRun::findOrFail($runId);
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->total_chunks);
        $this->assertSame(4, $run->students_processed);
        $this->assertSame(['fail' => 2, 'pass' => 1, 'withheld' => 1], collect($run->summary['students'])->sortKeys()->all());
        $this->assertSame('results_ready', $this->exam->refresh()->status);

        $s1 = $this->getJson($this->url('/results/S001'))->assertOk()->json('data');
        $this->assertSame('pass', $s1['status']);
        $this->assertEquals(9.14, $s1['sgpa']); // (4*10 + 3*8) / 7
        $this->assertSame(['O', 'A'], array_column($s1['courses'], 'grade'));

        $s2 = $this->getJson($this->url('/results/S002'))->json('data');
        $this->assertSame('fail', $s2['status']);
        $this->assertContains('below_component_minimum:END', $s2['courses'][0]['remarks']);

        $this->assertSame('withheld', $this->getJson($this->url('/results/S003'))->json('data.status'));
        $this->assertSame('AB', $this->getJson($this->url('/results/S004'))->json('data.courses.0.grade'));

        // Students cannot see anything until publication.
        $this->getJson('/api/v1/public/examinations/SEM1-2026/results/S001')->assertNotFound();

        $this->postJson($this->url('/publish'))->assertOk()->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/public/examinations/sem1-2026/results/s001')
            ->assertOk()
            ->assertJsonPath('data.status', 'pass')
            ->assertJsonPath('data.student.registration_no', 'S001');

        // Published results are final.
        $this->postJson($this->url('/unlock-marks'))->assertStatus(409)->assertJsonPath('error.code', 'invalid_state_transition');
    }

    public function test_correction_cycle_reprocesses_and_replaces_results(): void
    {
        $this->importMarks([
            ['S001', 'CS101', 'MID', '10'], ['S001', 'CS101', 'END', '20'], ['S001', 'CS102', 'LAB', '30'],
        ]);
        $this->postJson($this->url('/lock-marks'))->assertOk();
        $this->postJson($this->url('/result-runs'))->assertStatus(202);
        $this->assertSame('fail', $this->getJson($this->url('/results/S001'))->json('data.status'));

        // Found a data-entry error: reopen, fix, relock, reprocess.
        $this->postJson($this->url('/unlock-marks'))->assertOk()->assertJsonPath('data.status', 'marks_entry');
        $this->putJson($this->url('/marks'), [
            'registration_no' => 'S001', 'course_code' => 'CS102', 'component_code' => 'LAB', 'marks' => 90, 'expected_version' => 1,
        ])->assertOk();
        $this->putJson($this->url('/marks'), [
            'registration_no' => 'S001', 'course_code' => 'CS101', 'component_code' => 'END', 'marks' => 60, 'expected_version' => 1,
        ])->assertOk();
        $this->postJson($this->url('/lock-marks'))->assertOk();
        $second = $this->postJson($this->url('/result-runs'))->assertStatus(202)->json('data.id');

        $this->assertSame('pass', $this->getJson($this->url('/results/S001'))->json('data.status'));
        // Only rows from the latest run remain.
        $this->assertSame(0, ExaminationResult::where('result_run_id', '!=', $second)->count());
    }

    public function test_publish_requires_processed_results(): void
    {
        $this->postJson($this->url('/publish'))->assertStatus(409)->assertJsonPath('error.code', 'invalid_state_transition');

        $this->postJson($this->url('/lock-marks'))->assertOk();
        $this->postJson($this->url('/publish'))->assertStatus(409);
    }

    public function test_processing_cannot_start_while_marks_are_open(): void
    {
        $this->postJson($this->url('/result-runs'))
            ->assertStatus(409)
            ->assertJsonPath('error.details.current_status', 'marks_entry');

        $this->assertSame(0, ResultRun::count());
    }
}
