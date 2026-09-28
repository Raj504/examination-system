<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\Examination;
use App\Models\MarkImport;
use App\Models\ResultRun;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Moves an examination from one status to the next (see Examination::ALLOWED_MOVES).
 * This is the ONLY place that changes `examinations.status`.
 *
 * How we stop marks changing after they are locked:
 *   - Every status change locks the examination row with "SELECT ... FOR UPDATE".
 *   - Everything that writes marks first takes a *shared* lock on the same row
 *     (see lockForMarksWrite) and checks the status is still "marks_entry".
 *   - Shared locks don't block each other, so many mark writes run in parallel.
 *     But a status change waits until they finish, and any write that starts
 *     afterwards sees the new status and is refused.
 *
 * Asking for the status the examination already has does nothing (safe to repeat).
 */
class ExaminationLifecycle
{
    /** draft -> marks_entry. Every course must have components whose weights add up to 100. */
    public function openMarksEntry(Examination $exam): Examination
    {
        return $this->moveTo($exam->id, Examination::MARKS_ENTRY, function () use ($exam) {
            $courses = ExaminationCatalog::for($exam->id)->all();

            if ($courses === []) {
                throw AppException::unprocessable('Examination has no courses.', 'examination_has_no_courses');
            }

            foreach ($courses as $course) {
                $totalWeight = array_sum(array_column($course['components'], 'weight'));
                if ($course['components'] === [] || abs($totalWeight - 100) > 0.001) {
                    throw AppException::unprocessable(
                        "Course {$course['code']} components must have weights summing to 100.",
                        'invalid_component_weights',
                    );
                }
            }
        });
    }

    /** marks_entry -> marks_locked. Refused while CSV imports are still running. */
    public function lockMarks(Examination $exam): Examination
    {
        return $this->moveTo($exam->id, Examination::MARKS_LOCKED, function (Examination $locked) {
            $running = MarkImport::where('examination_id', $locked->id)
                ->whereIn('status', MarkImport::ACTIVE_STATUSES)
                ->count();

            if ($running > 0) {
                throw AppException::conflict(
                    "{$running} mark import(s) still running; wait for them to finish before locking.",
                    'imports_in_progress',
                );
            }
        });
    }

    /** marks_locked or results_ready -> marks_entry, to correct marks before publishing. */
    public function unlockMarks(Examination $exam): Examination
    {
        return $this->moveTo($exam->id, Examination::MARKS_ENTRY, function (Examination $locked) {
            if ($locked->status === Examination::DRAFT) {
                // A draft must go through openMarksEntry(), which validates the structure.
                throw AppException::conflict('Use open-marks-entry to start marks entry.', 'invalid_state_transition');
            }

            $locked->current_result_run_id = null; // old results no longer match the marks
        });
    }

    /** results_ready -> published. Students can see results from now on. */
    public function publish(Examination $exam): Examination
    {
        return $this->moveTo($exam->id, Examination::PUBLISHED, function (Examination $locked) {
            $run = $locked->current_result_run_id ? ResultRun::find($locked->current_result_run_id) : null;

            if ($run === null || $run->status !== 'completed') {
                throw AppException::conflict('No completed result run to publish.', 'results_not_ready');
            }

            $locked->published_at = now();
        });
    }

    /**
     * Changes the status inside a transaction, with the examination row locked.
     *
     * @param  Closure(Examination): void|null  $check  extra checks / changes made before saving;
     *                                                  throw an AppException to refuse the move
     */
    public function moveTo(int $examinationId, string $newStatus, ?Closure $check = null): Examination
    {
        return DB::transaction(function () use ($examinationId, $newStatus, $check) {
            $exam = Examination::whereKey($examinationId)->lockForUpdate()->firstOrFail();

            if ($exam->status === $newStatus) {
                return $exam; // already there: nothing to do
            }

            if (! $exam->canMoveTo($newStatus)) {
                throw AppException::conflict(
                    "Cannot move examination from {$exam->status} to {$newStatus}.",
                    'invalid_state_transition',
                    ['current_status' => $exam->status, 'requested_status' => $newStatus],
                );
            }

            if ($check !== null) {
                $check($exam);
            }

            $exam->status = $newStatus;
            $exam->save();

            return $exam;
        }, attempts: 3); // retried automatically if the database reports a deadlock
    }

    /**
     * Call this inside a transaction before writing marks. It takes a shared
     * lock on the examination and refuses if marks are not being accepted.
     */
    public static function lockForMarksWrite(int $examinationId): Examination
    {
        $exam = Examination::whereKey($examinationId)->sharedLock()->first();

        if ($exam === null) {
            throw AppException::notFound('Examination not found.');
        }

        if (! $exam->acceptsMarks()) {
            throw AppException::conflict(
                "Examination is {$exam->status}; marks can only be written during marks_entry.",
                'marks_not_accepted',
                ['current_status' => $exam->status],
            );
        }

        return $exam;
    }
}
