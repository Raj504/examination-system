<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\Examination;
use Illuminate\Support\Facades\DB;

final class EnrollmentService
{
    /**
     * Enrolls students into a course offering. Safe to repeat (idempotent): re-sending the same
     * registration numbers is a no-op thanks to the unique
     * (examination_course_id, student_id) key and INSERT IGNORE.
     *
     * @param  list<string>  $registrationNumbers
     * @return array{enrolled: int, already_enrolled: int, unknown_registration_nos: list<string>}
     */
    public function enroll(Examination $exam, string $courseCode, array $registrationNumbers): array
    {
        $course = ExaminationCatalog::for($exam->id)->course($courseCode)
            ?? throw AppException::unprocessable("Course {$courseCode} is not part of this examination.", 'unknown_course');

        $registrationNumbers = array_values(array_unique(array_map(fn ($r) => strtoupper(trim((string) $r)), $registrationNumbers)));

        $students = DB::table('students')
            ->whereIn('registration_no', $registrationNumbers)
            ->pluck('id', 'registration_no');

        $unknown = array_values(array_diff($registrationNumbers, $students->keys()->all()));

        $inserted = DB::transaction(function () use ($exam, $course, $students) {
            $locked = Examination::whereKey($exam->id)->sharedLock()->firstOrFail();

            if (! $locked->acceptsEnrollments()) {
                throw AppException::conflict(
                    "Enrollments are closed (examination is {$locked->status}).",
                    'enrollments_closed',
                );
            }

            $now = now();
            $inserted = 0;
            foreach ($students->values()->chunk(1000) as $chunk) {
                $inserted += DB::table('enrollments')->insertOrIgnore($chunk->map(fn ($studentId) => [
                    'examination_id' => $exam->id,
                    'examination_course_id' => $course['id'],
                    'student_id' => $studentId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            return $inserted;
        });

        return [
            'enrolled' => $inserted,
            'already_enrolled' => $students->count() - $inserted,
            'unknown_registration_nos' => $unknown,
        ];
    }
}
