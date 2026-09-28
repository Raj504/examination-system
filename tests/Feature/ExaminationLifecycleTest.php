<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Examination;
use App\Models\Programme;
use App\Models\ResultRun;
use App\Services\ExaminationLifecycle;
use App\Services\ResultProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\BuildsExaminations;
use Tests\TestCase;

class ExaminationLifecycleTest extends TestCase
{
    use BuildsExaminations, RefreshDatabase;

    public function test_structure_setup_via_api(): void
    {
        $this->postJson('/api/v1/programmes', ['code' => 'mba', 'name' => 'MBA'])->assertCreated();
        $this->postJson('/api/v1/courses', ['programme_code' => 'MBA', 'code' => 'fin1', 'title' => 'Finance', 'credits' => 3])->assertCreated();
        $this->postJson('/api/v1/students', ['students' => [
            ['registration_no' => 'm1', 'name' => 'A', 'programme_code' => 'MBA'],
            ['registration_no' => 'M2', 'name' => 'B', 'programme_code' => 'mba'],
        ]])->assertOk()->assertJsonPath('data.upserted', 2);

        $examId = $this->postJson('/api/v1/examinations', ['code' => 'mba-t1', 'name' => 'Term 1', 'academic_year' => '2026'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->putJson("/api/v1/examinations/{$examId}/courses/FIN1", ['components' => [
            ['code' => 'quiz', 'name' => 'Quiz', 'max_marks' => 20, 'weight' => 20],
            ['code' => 'final', 'name' => 'Final', 'max_marks' => 80, 'weight' => 70],
        ]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_component_weights');

        $this->putJson("/api/v1/examinations/{$examId}/courses/FIN1", ['components' => [
            ['code' => 'quiz', 'name' => 'Quiz', 'max_marks' => 20, 'weight' => 20],
            ['code' => 'final', 'name' => 'Final', 'max_marks' => 80, 'weight' => 80],
        ]])->assertOk();

        $this->postJson("/api/v1/examinations/{$examId}/enrollments", ['course_code' => 'fin1', 'registration_nos' => ['M1', 'M2', 'M3']])
            ->assertOk()
            ->assertJsonPath('data.enrolled', 2)
            ->assertJsonPath('data.unknown_registration_nos', ['M3']);

        // Re-enrolling is a no-op.
        $this->postJson("/api/v1/examinations/{$examId}/enrollments", ['course_code' => 'FIN1', 'registration_nos' => ['M1']])
            ->assertOk()
            ->assertJsonPath('data.enrolled', 0)
            ->assertJsonPath('data.already_enrolled', 1);

        $this->postJson("/api/v1/examinations/{$examId}/open-marks-entry")->assertOk()->assertJsonPath('data.status', 'marks_entry');

        // Structure is frozen after draft.
        $this->putJson("/api/v1/examinations/{$examId}/courses/FIN1", ['components' => [
            ['code' => 'final', 'name' => 'Final', 'max_marks' => 100, 'weight' => 100],
        ]])->assertStatus(409)->assertJsonPath('error.code', 'structure_locked');

        $this->getJson("/api/v1/examinations/{$examId}/courses")->assertOk()->assertJsonCount(2, 'data.0.components');
    }

    public function test_cannot_open_examination_without_courses(): void
    {
        $exam = Examination::create(['code' => 'EMPTY', 'name' => 'Empty', 'academic_year' => '2026']);

        $this->postJson("/api/v1/examinations/{$exam->id}/open-marks-entry")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'examination_has_no_courses');
    }

    public function test_transitions_are_idempotent(): void
    {
        $exam = $this->examinationInMarksEntry(1);

        $this->postJson("/api/v1/examinations/{$exam->id}/lock-marks")->assertOk();
        $this->postJson("/api/v1/examinations/{$exam->id}/lock-marks")->assertOk()->assertJsonPath('data.status', 'marks_locked');
    }

    public function test_starting_processing_twice_returns_the_same_run(): void
    {
        Queue::fake(); // keep the run in-flight
        $exam = $this->examinationInMarksEntry(1);
        app(ExaminationLifecycle::class)->lockMarks($exam);

        $service = app(ResultProcessingService::class);
        $first = $service->start($exam, 'a');
        $second = $service->start($exam, 'b');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ResultRun::count());
        $this->assertSame('processing', $exam->refresh()->status);
    }

    public function test_invalid_transition_reports_current_state(): void
    {
        $programme = Programme::create(['code' => 'P', 'name' => 'P']);
        Course::create(['programme_id' => $programme->id, 'code' => 'C', 'title' => 'C', 'credits' => 1]);
        $exam = Examination::create(['code' => 'X', 'name' => 'X', 'academic_year' => '2026']);

        $this->postJson("/api/v1/examinations/{$exam->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('error.details.current_status', 'draft')
            ->assertJsonPath('error.details.requested_status', 'published');
    }
}
