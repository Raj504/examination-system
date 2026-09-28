<?php

namespace App\Jobs;

use App\Models\ResultRun;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Fans a result run out into chunks of N students.
 *
 * Chunks are student-id RANGES found with keyset pagination over the
 * (examination_id, student_id) index, so the job payload is two integers and
 * the planner never loads the student list into memory. A student is never
 * split across chunks, which lets one chunk compute both course results and
 * the SGPA for its students without coordination.
 */
class PlanResultRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(config('exams.results.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(): void
    {
        $run = ResultRun::find($this->runId);
        if ($run === null || $run->status !== 'processing' || $run->batch_id !== null) {
            return;
        }

        $size = max(1, (int) config('exams.results.students_per_chunk'));
        $jobs = [];
        $after = 0;

        while (true) {
            $ids = DB::table('enrollments')
                ->where('examination_id', $run->examination_id)
                ->where('student_id', '>', $after)
                ->distinct()
                ->orderBy('student_id')
                ->limit($size)
                ->pluck('student_id');

            if ($ids->isEmpty()) {
                break;
            }

            $jobs[] = new ComputeResultsChunk($run->id, (int) $ids->first(), (int) $ids->last());
            $after = (int) $ids->last();
        }

        $run->update(['total_chunks' => count($jobs)]);

        if ($jobs === []) {
            FinalizeResultRun::dispatch($run->id, false);

            return;
        }

        $runId = $run->id;
        $batch = Bus::batch($jobs)
            ->name("result-run:{$runId}")
            ->onQueue(config('exams.results.queue'))
            // Results are all-or-nothing: a permanently failing chunk cancels
            // the batch and the run is marked failed (marks stay locked).
            ->finally(function (Batch $batch) use ($runId) {
                FinalizeResultRun::dispatch($runId, $batch->hasFailures());
            })
            ->dispatch();

        ResultRun::whereKey($runId)->update(['batch_id' => $batch->id]);
    }
}
