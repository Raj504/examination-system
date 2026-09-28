<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\Mark;
use App\Models\MarkAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExaminations;
use Tests\TestCase;

class MarkEntryTest extends TestCase
{
    use BuildsExaminations, RefreshDatabase;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exam = $this->examinationInMarksEntry(2);
    }

    private function putMark(array $body, array $headers = [])
    {
        return $this->putJson("/api/v1/examinations/{$this->exam->id}/marks", [
            'registration_no' => 'S001',
            'course_code' => 'CS101',
            'component_code' => 'MID',
            ...$body,
        ], $headers);
    }

    public function test_create_then_update_with_version(): void
    {
        $this->putMark(['marks' => 20])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.marks', '20.00')
            ->assertHeader('ETag', '"1"');

        $this->putMark(['marks' => 22.5, 'expected_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.marks', '22.50');

        $this->assertSame(2, MarkAudit::count());
        $this->assertSame('20.00', MarkAudit::latest('id')->first()->old_marks);
    }

    public function test_stale_version_is_rejected_with_current_state(): void
    {
        $this->putMark(['marks' => 20])->assertCreated();
        $this->putMark(['marks' => 21, 'expected_version' => 1])->assertOk();

        // A second examiner still holding version 1.
        $this->putMark(['marks' => 5, 'expected_version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'version_conflict')
            ->assertJsonPath('error.details.current.version', 2)
            ->assertJsonPath('error.details.current.marks', '21.00');

        $this->assertSame('21.00', Mark::sole()->marks_obtained);
    }

    public function test_if_match_header_is_accepted_as_version(): void
    {
        $this->putMark(['marks' => 20])->assertCreated();

        $this->putMark(['marks' => 25], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_blind_overwrite_requires_a_version(): void
    {
        $this->putMark(['marks' => 20])->assertCreated();

        $this->putMark(['marks' => 25])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'precondition_required');
    }

    public function test_absent_is_recorded_without_marks(): void
    {
        $this->putMark(['absent' => true])->assertCreated()->assertJsonPath('data.absent', true)->assertJsonPath('data.marks', null);
    }

    public function test_business_validation(): void
    {
        $this->putMark(['marks' => 30.01])->assertStatus(422)->assertJsonPath('error.code', 'marks_exceed_maximum');
        $this->putMark(['marks' => '10.123'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_marks');
        $this->putMark(['component_code' => 'NOPE', 'marks' => 1])->assertStatus(422)->assertJsonPath('error.code', 'unknown_component');
        $this->putMark(['registration_no' => 'S404', 'marks' => 1])->assertStatus(422)->assertJsonPath('error.code', 'not_enrolled');
        $this->putMark(['marks' => -1])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_marks_are_frozen_once_locked(): void
    {
        $this->putMark(['marks' => 20])->assertCreated();
        $this->postJson("/api/v1/examinations/{$this->exam->id}/lock-marks")->assertOk();

        $this->putMark(['marks' => 25, 'expected_version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'marks_not_accepted');

        $this->assertSame('20.00', Mark::sole()->marks_obtained);
    }
}
