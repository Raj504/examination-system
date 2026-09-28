<?php

namespace App\Jobs;

use App\Exceptions\AppException;
use App\Models\Examination;
use App\Models\ResultRun;
use App\Services\ExaminationLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Closes a result run: PROCESSING -> RESULTS_READY on success, or back to
 * MARKS_LOCKED on failure so the run can simply be triggered again.
 */
class FinalizeResultRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $runId,
        public readonly bool $hadFailures,
    ) {
        $this->onQueue(config('exams.results.queue'));
    }

    public function handle(ExaminationLifecycle $lifecycle): void
    {
        $run = ResultRun::find($this->runId);
        if ($run === null || $run->status !== 'processing') {
            return;
        }

        $target = $this->hadFailures ? Examination::MARKS_LOCKED : Examination::RESULTS_READY;

        try {
            $lifecycle->moveTo($run->examination_id, $target, function (Examination $exam) use ($run) {
                if ($exam->status !== Examination::PROCESSING || $exam->current_result_run_id !== $run->id) {
                    throw AppException::conflict('Run was superseded.', 'run_superseded');
                }

                $this->hadFailures ? $this->markFailed($run) : $this->markCompleted($run);
            });
        } catch (AppException) {
            // Examination moved on (superseded / already transitioned): retire the run.
            ResultRun::whereKey($run->id)->where('status', 'processing')->update([
                'status' => 'failed',
                'failure_reason' => 'Superseded before completion.',
                'finished_at' => now(),
            ]);
        }
    }

    private function markCompleted(ResultRun $run): void
    {
        $examId = $run->examination_id;

        // Drop rows left behind by earlier runs so the current run is the
        // single source of truth for this examination.
        DB::table('course_results')->where('examination_id', $examId)->where('result_run_id', '!=', $run->id)->delete();
        DB::table('examination_results')->where('examination_id', $examId)->where('result_run_id', '!=', $run->id)->delete();

        $students = DB::table('examination_results')->where('examination_id', $examId)
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $courses = DB::table('course_results')->where('examination_id', $examId)
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $run->update([
            'status' => 'completed',
            'students_processed' => (int) $students->sum(),
            'summary' => [
                'students' => $students->map(fn ($n) => (int) $n)->all(),
                'course_results' => $courses->map(fn ($n) => (int) $n)->all(),
            ],
            'finished_at' => now(),
        ]);
    }

    private function markFailed(ResultRun $run): void
    {
        $run->update([
            'status' => 'failed',
            'failure_reason' => 'One or more chunks failed after retries; see failed_jobs. Re-trigger processing to retry.',
            'finished_at' => now(),
        ]);
    }
}
