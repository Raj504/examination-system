<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Examination;
use App\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseOfferingRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENTS = [
        ['code' => 'MID', 'name' => 'Mid term', 'max_marks' => 30, 'weight' => 30, 'min_pass_marks' => 20],
        ['code' => 'END', 'name' => 'End term', 'max_marks' => 70, 'weight' => 70, 'min_pass_marks' => 28],
    ];

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $programme = Programme::create(['code' => 'BTECH', 'name' => 'B.Tech']);
        Course::create(['programme_id' => $programme->id, 'code' => 'CS101', 'title' => 'Programming', 'credits' => 4]);
        $this->exam = Examination::create(['code' => 'SEM1', 'name' => 'Semester 1', 'academic_year' => '2026-27']);
    }

    public function test_course_code_in_path(): void
    {
        $this->putJson("/api/v1/examinations/{$this->exam->id}/courses/cs101", ['pass_percentage' => 40, 'components' => self::COMPONENTS])
            ->assertOk()
            ->assertJsonCount(2, 'data.components');
    }

    public function test_course_code_in_body(): void
    {
        $this->putJson("/api/v1/examinations/{$this->exam->id}/courses", ['course_code' => 'CS101', 'components' => self::COMPONENTS])
            ->assertOk();
        $this->postJson("/api/v1/examinations/{$this->exam->id}/courses", ['course_code' => 'CS101', 'components' => self::COMPONENTS])
            ->assertOk();

        $this->getJson("/api/v1/examinations/{$this->exam->id}/courses")->assertJsonCount(1, 'data');
    }

    public function test_missing_course_code_is_a_clear_validation_error(): void
    {
        $this->putJson("/api/v1/examinations/{$this->exam->id}/courses", ['components' => self::COMPONENTS])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors('course_code', 'error.details');
    }

    public function test_wrong_method_returns_json_405_without_stack_trace(): void
    {
        $this->deleteJson("/api/v1/examinations/{$this->exam->id}/courses")
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed')
            ->assertJsonMissingPath('trace');
    }
}
