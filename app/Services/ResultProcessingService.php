<?php

namespace App\Services;

use App\Jobs\PlanResultRun;
use App\Models\Examination;
use App\Models\ResultRun;

final class ResultProcessingService
{
    public function __construct(private readonly ExaminationLifecycle $lifecycle) {}

    /**
     * Starts an asynchronous result run. Safe to repeat (idempotent): while a run is in
     * progress, repeated calls return that run instead of starting another,
     * because the MARKS_LOCKED -> PROCESSING transition happens exactly once
     * under the examination row lock.
     */
    public function start(Examination $exam, string $actor): ResultRun
    {
        $run = null;

        $exam = $this->lifecycle->moveTo(
            $exam->id,
            Examination::PROCESSING,
            function (Examination $locked) use (&$run, $actor) {
                $run = ResultRun::create([
                    'examination_id' => $locked->id,
                    'status' => 'processing',
                    'triggered_by' => $actor,
                    'started_at' => now(),
                ]);

                $locked->current_result_run_id = $run->id;

                PlanResultRun::dispatch($run->id)->afterCommit();
            },
        );

        return $run ?? ResultRun::findOrFail($exam->current_result_run_id);
    }
}
