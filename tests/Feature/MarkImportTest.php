<?php

namespace Tests\Feature;

use App\Jobs\FinalizeMarkImport;
use App\Jobs\ProcessMarkImportChunk;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\MarkImport;
use App\Models\MarkImportChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExaminations;
use Tests\TestCase;

class MarkImportTest extends TestCase
{
    use BuildsExaminations, RefreshDatabase;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['exams.imports.chunk_size' => 2]); // force several chunks
        $this->exam = $this->examinationInMarksEntry(3);
    }

    private function upload(string $content, string $name = 'marks.csv')
    {
        return $this->post("/api/v1/examinations/{$this->exam->id}/mark-imports", [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ], ['Accept' => 'application/json']);
    }

    public function test_valid_file_is_imported_across_chunks(): void
    {
        $response = $this->upload($this->csv([
            ['S001', 'CS101', 'MID', '25'],
            ['S001', 'CS101', 'END', '60.5'],
            ['s002', 'cs101', 'mid', 'AB'],   // case-insensitive, absent token
            ['S002', 'CS101', 'END', '40'],
            ['S003', 'CS102', 'LAB', '99.99'],
        ]));

        $response->assertStatus(202)->assertJsonPath('meta.duplicate_of_existing_upload', false);

        $import = MarkImport::sole();
        $this->assertSame(MarkImport::COMPLETED, $import->status);
        $this->assertSame(5, $import->total_rows);
        $this->assertSame(3, $import->total_chunks);
        $this->assertSame(5, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);

        $this->assertSame(5, Mark::count());
        $this->assertTrue(Mark::where('is_absent', true)->exists());
        $this->assertSame('60.50', Mark::where('marks_obtained', 60.5)->value('marks_obtained'));

        $this->getJson("/api/v1/mark-imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_invalid_rows_are_reported_and_valid_rows_are_kept(): void
    {
        $this->upload($this->csv([
            ['S001', 'CS101', 'MID', '31'],       // exceeds max 30
            ['S999', 'CS101', 'MID', '10'],       // unknown student
            ['S001', 'CS999', 'MID', '10'],       // course not in exam
            ['S001', 'CS101', 'XYZ', '10'],       // unknown component
            ['S001', 'CS101', 'END', '12.345'],   // too many decimals
            ['S002', 'CS101', 'END', ''],         // missing marks
            ['S003', 'CS102', 'LAB', '50'],       // valid
        ]))->assertStatus(202);

        $import = MarkImport::sole();
        $this->assertSame(MarkImport::COMPLETED_WITH_ERRORS, $import->status);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(6, $import->failed_rows);
        $this->assertSame(1, Mark::count());

        $errors = $this->getJson("/api/v1/mark-imports/{$import->id}/errors")->assertOk()->json('data');
        $this->assertSame([2, 3, 4, 5, 6, 7], array_column($errors, 'row_number'));
        $this->assertStringContainsString('exceeds maximum 30.00', $errors[0]['errors'][0]);
        $this->assertStringContainsString('not found', $errors[1]['errors'][0]);

        $csv = $this->get("/api/v1/mark-imports/{$import->id}/errors.csv")->assertOk()->streamedContent();
        $this->assertStringStartsWith('row_number,errors,raw', $csv);
        $this->assertSame(7, substr_count(trim($csv), "\n") + 1);
    }

    public function test_duplicate_keys_in_file_are_rejected_deterministically(): void
    {
        $this->upload($this->csv([
            ['S001', 'CS101', 'MID', '10'],
            ['S002', 'CS101', 'MID', '11'],
            ['s001', 'cs101', 'mid', '29'],  // duplicate of row 2, lands in another chunk
        ]));

        $import = MarkImport::sole();
        $this->assertSame(MarkImport::COMPLETED_WITH_ERRORS, $import->status);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame(1, $import->failed_rows);
        $this->assertSame(3, $import->processed_rows);

        // First occurrence wins regardless of chunk execution order.
        $this->assertSame('10.00', Mark::orderBy('id')->first()->marks_obtained);
        $error = $import->errors()->sole();
        $this->assertSame(4, $error->row_number);
        $this->assertStringContainsString('as row 2', $error->errors[0]);
    }

    public function test_reuploading_the_same_file_does_not_process_twice(): void
    {
        $content = $this->csv([['S001', 'CS101', 'MID', '10']]);

        $this->upload($content)->assertStatus(202);
        $first = MarkImport::sole();

        $this->upload($content, 'renamed.csv')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('meta.duplicate_of_existing_upload', true);

        $this->assertSame(1, MarkImport::count());
        $this->assertSame(1, Mark::sole()->version);
    }

    public function test_chunk_redelivery_is_idempotent(): void
    {
        $this->upload($this->csv([
            ['S001', 'CS101', 'MID', '10'],
            ['S999', 'CS101', 'MID', '10'],
        ]));
        $import = MarkImport::sole();

        // Simulate at-least-once delivery: the chunk runs again after it
        // committed (e.g. the worker died before acknowledging the job).
        MarkImportChunk::where('mark_import_id', $import->id)->update(['status' => 'pending']);
        $import->update(['status' => MarkImport::PROCESSING]);
        (new ProcessMarkImportChunk($import->id, 0))->handle();
        (new FinalizeMarkImport($import->id))->handle();

        $import->refresh();
        $this->assertSame(1, Mark::count());
        $this->assertSame(1, $import->errors()->count());
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(1, $import->failed_rows);
    }

    public function test_quoted_multiline_fields_keep_chunk_offsets_aligned(): void
    {
        $content = "registration_no,course_code,component_code,marks,comment\n"
            ."S001,CS101,MID,10,\"line one\nline two\"\n"
            ."S002,CS101,MID,11,plain\n"
            ."\n"
            ."S003,CS101,MID,12,\"a, b\"\n";

        $this->upload($content);

        $import = MarkImport::sole();
        $this->assertSame(MarkImport::COMPLETED, $import->status);
        $this->assertSame(3, Mark::count());
        $this->assertEqualsCanonicalizing(['10.00', '11.00', '12.00'], Mark::pluck('marks_obtained')->all());
    }

    public function test_bad_header_fails_the_import(): void
    {
        $this->upload("student,course,marks\nS001,CS101,10\n");

        $import = MarkImport::sole();
        $this->assertSame(MarkImport::FAILED, $import->status);
        $this->assertStringContainsString('component_code', $import->failure_reason);
        $this->assertSame(0, Mark::count());
    }

    public function test_upload_rejected_when_marks_entry_is_closed(): void
    {
        $this->postJson("/api/v1/examinations/{$this->exam->id}/lock-marks")->assertOk();

        $this->upload($this->csv([['S001', 'CS101', 'MID', '10']]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'marks_not_accepted');

        $this->assertSame(0, MarkImport::count());
        Storage::disk('local')->assertDirectoryEmpty("imports/{$this->exam->id}");
    }

    public function test_marks_cannot_be_locked_while_an_import_is_running(): void
    {
        MarkImport::create([
            'examination_id' => $this->exam->id,
            'original_filename' => 'x.csv',
            'file_path' => 'x.csv',
            'file_hash' => str_repeat('a', 64),
            'status' => MarkImport::PROCESSING,
        ]);

        $this->postJson("/api/v1/examinations/{$this->exam->id}/lock-marks")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'imports_in_progress');
    }

    public function test_failed_chunks_can_be_retried(): void
    {
        $this->upload($this->csv([
            ['S001', 'CS101', 'MID', '10'],
            ['S002', 'CS101', 'MID', '11'],
            ['S003', 'CS101', 'MID', '12'],
        ]));
        $import = MarkImport::sole();

        // Simulate chunk 1 having exhausted its retries.
        Mark::whereIn('enrollment_id', Mark::orderByDesc('id')->limit(1)->pluck('enrollment_id'))->delete();
        MarkImportChunk::where('mark_import_id', $import->id)->where('chunk_index', 1)->update(['status' => 'failed']);
        $import->update(['status' => MarkImport::PROCESSING]);
        (new FinalizeMarkImport($import->id))->handle();

        $this->assertSame(MarkImport::FAILED, $import->refresh()->status);
        $this->assertStringContainsString('1 chunk(s) failed', $import->failure_reason);

        $this->postJson("/api/v1/mark-imports/{$import->id}/retry")->assertStatus(202);

        $import->refresh();
        $this->assertSame(MarkImport::COMPLETED, $import->status);
        $this->assertSame(3, Mark::count());
        $this->assertSame(3, $import->success_rows);
    }
}
