<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\MarkAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Interactive, single-mark entry with optimistic concurrency control.
 *
 * Two examiners editing the same mark cannot silently overwrite each other:
 * an update must quote the `version` it was based on, and the write is a
 * compare-and-swap (`UPDATE ... WHERE version = ?`). A stale version yields
 * 409 with the current value so the client can reconcile.
 */
final class MarkEntryService
{
    /**
     * @param  array{registration_no: string, course_code: string, component_code: string, marks?: string|int|float|null, absent?: bool}  $input
     */
    public function upsert(Examination $exam, array $input, ?int $expectedVersion, string $actor): Mark
    {
        $catalog = ExaminationCatalog::for($exam->id);

        $course = $catalog->course($input['course_code'])
            ?? throw AppException::unprocessable('Course is not part of this examination.', 'unknown_course');
        $component = $course['components'][strtoupper(trim($input['component_code']))]
            ?? throw AppException::unprocessable('Unknown assessment component for course.', 'unknown_component');

        $absent = (bool) ($input['absent'] ?? false);
        $hundredths = null;

        if (! $absent) {
            $hundredths = isset($input['marks']) ? MarkParser::toHundredths($input['marks']) : null;
            if ($hundredths === null) {
                throw AppException::unprocessable('marks must be a non-negative number with at most 2 decimals, or absent=true.', 'invalid_marks');
            }
            if ($hundredths > $component['max']) {
                throw AppException::unprocessable(
                    'marks exceed the component maximum of '.MarkParser::format($component['max']).'.',
                    'marks_exceed_maximum',
                );
            }
        }

        $enrollmentId = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('s.registration_no', strtoupper(trim($input['registration_no'])))
            ->where('e.examination_course_id', $course['id'])
            ->value('e.id')
            ?? throw AppException::unprocessable('Student is not enrolled in this course for this examination.', 'not_enrolled');

        $newMarks = $hundredths === null ? null : MarkParser::format($hundredths);

        return DB::transaction(function () use ($exam, $component, $enrollmentId, $absent, $newMarks, $expectedVersion, $actor) {
            ExaminationLifecycle::lockForMarksWrite($exam->id);

            $existing = Mark::where('enrollment_id', $enrollmentId)
                ->where('assessment_component_id', $component['id'])
                ->first();

            if ($existing === null) {
                if ($expectedVersion !== null && $expectedVersion !== 0) {
                    throw AppException::conflict('Mark does not exist yet; omit expected_version (or send 0) to create it.', 'version_conflict');
                }

                try {
                    $mark = Mark::create([
                        'examination_id' => $exam->id,
                        'enrollment_id' => $enrollmentId,
                        'assessment_component_id' => $component['id'],
                        'marks_obtained' => $newMarks,
                        'is_absent' => $absent,
                        'version' => 1,
                        'source' => 'api',
                        'updated_by' => $actor,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw AppException::conflict('Mark was created concurrently; re-read and retry with its version.', 'version_conflict');
                }

                $this->audit($mark, null, null, $actor);

                return $mark;
            }

            if ($expectedVersion === null) {
                throw new AppException(
                    'Mark already exists; send expected_version to update it.',
                    'precondition_required',
                    428,
                    ['current' => $this->snapshot($existing)],
                );
            }

            $updated = Mark::whereKey($existing->id)
                ->where('version', $expectedVersion)
                ->update([
                    'marks_obtained' => $newMarks,
                    'is_absent' => $absent,
                    'version' => DB::raw('version + 1'),
                    'source' => 'api',
                    'mark_import_id' => null,
                    'updated_by' => $actor,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw AppException::conflict(
                    'Mark was modified by someone else.',
                    'version_conflict',
                    // Locking read: always returns the latest committed row, even
                    // under a snapshot isolation level.
                    ['current' => $this->snapshot(Mark::whereKey($existing->id)->sharedLock()->first())],
                );
            }

            $mark = $existing->fresh();
            $this->audit($mark, $existing->marks_obtained, $existing->is_absent, $actor);

            return $mark;
        }, attempts: 3);
    }

    private function audit(Mark $mark, ?string $oldMarks, ?bool $oldAbsent, string $actor): void
    {
        MarkAudit::create([
            'mark_id' => $mark->id,
            'old_marks' => $oldMarks,
            'old_is_absent' => $oldAbsent,
            'new_marks' => $mark->marks_obtained,
            'new_is_absent' => $mark->is_absent,
            'new_version' => $mark->version,
            'changed_by' => $actor,
        ]);
    }

    private function snapshot(Mark $mark): array
    {
        return [
            'marks' => $mark->marks_obtained,
            'absent' => $mark->is_absent,
            'version' => $mark->version,
            'updated_by' => $mark->updated_by,
            'updated_at' => $mark->updated_at?->toIso8601String(),
        ];
    }
}
