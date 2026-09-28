<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\ResultRun;
use App\Services\ResultProcessingService;
use App\Services\StudentResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class ResultController extends Controller
{
    /** Starts (or returns the in-flight) asynchronous result run. */
    public function process(Request $request, Examination $examination, ResultProcessingService $service): JsonResponse
    {
        $run = $service->start($examination, $this->actor($request));

        return response()->json(['data' => $this->runPayload($run->refresh())], 202)
            ->header('Location', url("/api/v1/result-runs/{$run->id}"));
    }

    public function runs(Examination $examination): JsonResponse
    {
        $runs = $examination->resultRuns()->orderByDesc('id')->limit(50)->get()->map(fn ($r) => $this->runPayload($r));

        return response()->json(['data' => $runs]);
    }

    public function run(ResultRun $resultRun): JsonResponse
    {
        return response()->json(['data' => $this->runPayload($resultRun)]);
    }

    /** Staff view of computed results (available once results are ready). */
    public function index(Request $request, Examination $examination): JsonResponse
    {
        $this->assertResultsAvailable($examination);

        $results = DB::table('examination_results as r')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->where('r.examination_id', $examination->id)
            ->when($request->query('status'), fn ($q, $v) => $q->where('r.status', $v))
            ->orderBy('r.id')
            ->select(['r.id', 's.registration_no', 's.name', 'r.sgpa', 'r.credits_attempted', 'r.credits_earned', 'r.status'])
            ->cursorPaginate(min((int) $request->query('per_page', 200), 1000));

        return response()->json($results);
    }

    public function show(Examination $examination, string $registrationNo): JsonResponse
    {
        $this->assertResultsAvailable($examination);

        return response()->json(['data' => $this->studentResult($examination, $registrationNo)]);
    }

    /**
     * Student-facing lookup. Only published examinations are visible, and the
     * response is identical for "not published" and "no such student" to
     * avoid leaking whether results exist.
     */
    public function publicShow(string $examCode, string $registrationNo): JsonResponse
    {
        $examination = Examination::where('code', strtoupper($examCode))->first();

        if ($examination === null || ! $examination->isPublished()) {
            throw AppException::notFound('Result not available.', 'result_not_available');
        }

        return response()->json(['data' => $this->studentResult($examination, $registrationNo)]);
    }

    private function studentResult(Examination $examination, string $registrationNo): array
    {
        return app(StudentResultService::class)->find($examination, $registrationNo)
            ?? throw AppException::notFound('Result not available.', 'result_not_available');
    }

    private function assertResultsAvailable(Examination $examination): void
    {
        if (! $examination->hasResults()) {
            throw AppException::conflict(
                "Results are not available while the examination is {$examination->status}.",
                'results_not_ready',
            );
        }
    }

    private function runPayload(ResultRun $run): array
    {
        $progress = null;
        if ($run->status === 'processing' && $run->batch_id) {
            $batch = Bus::findBatch($run->batch_id);
            $progress = $batch ? [
                'chunks_total' => $batch->totalJobs,
                'chunks_pending' => $batch->pendingJobs,
                'chunks_failed' => $batch->failedJobs,
                'percent' => $batch->progress(),
            ] : null;
        }

        return [
            'id' => $run->id,
            'examination_id' => $run->examination_id,
            'status' => $run->status,
            'total_chunks' => $run->total_chunks,
            'students_processed' => $run->students_processed,
            'progress' => $progress,
            'summary' => $run->summary,
            'failure_reason' => $run->failure_reason,
            'triggered_by' => $run->triggered_by,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
