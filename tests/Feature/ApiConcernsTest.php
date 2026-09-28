<?php

namespace Tests\Feature;

use App\Models\Mark;
use App\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExaminations;
use Tests\TestCase;

class ApiConcernsTest extends TestCase
{
    use BuildsExaminations, RefreshDatabase;

    public function test_idempotency_key_replays_the_first_response(): void
    {
        $exam = $this->examinationInMarksEntry(1);
        $body = ['registration_no' => 'S001', 'course_code' => 'CS101', 'component_code' => 'MID', 'marks' => 20];
        $headers = ['Idempotency-Key' => 'mark-entry-0001'];

        $first = $this->putJson("/api/v1/examinations/{$exam->id}/marks", $body, $headers)->assertCreated();

        // A network retry of the same request must not fail with 428 or bump the version.
        $this->putJson("/api/v1/examinations/{$exam->id}/marks", $body, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJson($first->json());

        $this->assertSame(1, Mark::sole()->version);
    }

    public function test_idempotency_key_reuse_with_different_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/programmes', ['code' => 'A', 'name' => 'A'], ['Idempotency-Key' => 'create-prog-1'])->assertCreated();

        $this->postJson('/api/v1/programmes', ['code' => 'B', 'name' => 'B'], ['Idempotency-Key' => 'create-prog-1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertSame(1, Programme::count());
    }

    public function test_api_key_authentication_when_configured(): void
    {
        config(['exams.api_keys' => 'registrar:s3cret']);

        $this->getJson('/api/v1/programmes')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson('/api/v1/programmes', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->getJson('/api/v1/programmes', ['Authorization' => 'Bearer s3cret'])->assertOk();
        $this->getJson('/api/v1/programmes', ['X-Api-Key' => 's3cret'])->assertOk();
    }

    public function test_actor_is_recorded_on_writes(): void
    {
        config(['exams.api_keys' => 'examiner-7:tok']);
        $exam = $this->examinationInMarksEntry(1);

        $this->putJson("/api/v1/examinations/{$exam->id}/marks", [
            'registration_no' => 'S001', 'course_code' => 'CS101', 'component_code' => 'MID', 'marks' => 20,
        ], ['X-Api-Key' => 'tok'])->assertCreated()->assertJsonPath('data.updated_by', 'examiner-7');
    }

    public function test_unknown_resources_return_json_404(): void
    {
        $this->getJson('/api/v1/examinations/999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
