<?php

namespace App\Services\Imports;

use App\Services\ExaminationCatalog;
use App\Services\MarkParser;
use Illuminate\Support\Facades\DB;

/**
 * Validates and resolves one chunk of CSV rows with a constant number of
 * queries (one for students, one for enrollments) regardless of chunk size.
 *
 * Every problem on a row is reported, not just the first, so an operator can
 * fix a file in one pass.
 */
final class MarkRowValidator
{
    public function __construct(private readonly ExaminationCatalog $catalog) {}

    /**
     * @param  iterable<int, array>  $rows  row number => raw record
     * @param  array<string, int>  $columns
     * @return array{valid: list<array{row: int, enrollment_id: int, component_id: int, marks: ?string, absent: bool}>, errors: list<array{row: int, raw: string, errors: list<string>}>}
     */
    public function validate(iterable $rows, array $columns): array
    {
        $parsed = [];
        $errors = [];

        foreach ($rows as $rowNumber => $record) {
            $rowErrors = [];

            if (count($record) < count(MarkCsv::REQUIRED_COLUMNS)) {
                $errors[] = $this->error($rowNumber, $record, ['malformed_row: expected at least '.count(MarkCsv::REQUIRED_COLUMNS).' columns']);

                continue;
            }

            $registrationNo = strtoupper(MarkCsv::field($record, $columns, 'registration_no'));
            $courseCode = MarkCsv::field($record, $columns, 'course_code');
            $componentCode = MarkCsv::field($record, $columns, 'component_code');
            $rawMarks = MarkCsv::field($record, $columns, 'marks');

            if ($registrationNo === '') {
                $rowErrors[] = 'registration_no: required';
            }

            $course = $courseCode === '' ? null : $this->catalog->course($courseCode);
            $component = null;
            if ($courseCode === '') {
                $rowErrors[] = 'course_code: required';
            } elseif ($course === null) {
                $rowErrors[] = "course_code: {$courseCode} is not part of this examination";
            } else {
                $component = $course['components'][strtoupper($componentCode)] ?? null;
                if ($component === null) {
                    $rowErrors[] = "component_code: {$componentCode} is not a component of {$courseCode}";
                }
            }

            $absent = MarkParser::isAbsent($rawMarks);
            $hundredths = null;
            if (! $absent) {
                $hundredths = MarkParser::toHundredths($rawMarks);
                if ($rawMarks === '') {
                    $rowErrors[] = 'marks: required (use AB for absent)';
                } elseif ($hundredths === null) {
                    $rowErrors[] = "marks: '{$rawMarks}' is not a non-negative number with at most 2 decimals";
                } elseif ($component !== null && $hundredths > $component['max']) {
                    $rowErrors[] = "marks: {$rawMarks} exceeds maximum ".MarkParser::format($component['max']);
                }
            }

            if ($rowErrors !== []) {
                $errors[] = $this->error($rowNumber, $record, $rowErrors);

                continue;
            }

            $parsed[$rowNumber] = [
                'record' => $record,
                'registration_no' => $registrationNo,
                'examination_course_id' => $course['id'],
                'component_id' => $component['id'],
                'marks' => $absent ? null : MarkParser::format($hundredths),
                'absent' => $absent,
            ];
        }

        if ($parsed === []) {
            return ['valid' => [], 'errors' => $errors];
        }

        $studentIds = DB::table('students')
            ->whereIn('registration_no', array_unique(array_column($parsed, 'registration_no')))
            ->pluck('id', 'registration_no');

        $enrollments = [];
        if ($studentIds->isNotEmpty()) {
            DB::table('enrollments')
                ->where('examination_id', $this->catalog->examinationId)
                ->whereIn('student_id', $studentIds->values()->all())
                ->get(['id', 'student_id', 'examination_course_id'])
                ->each(function ($e) use (&$enrollments) {
                    $enrollments[$e->student_id.':'.$e->examination_course_id] = (int) $e->id;
                });
        }

        $valid = [];
        foreach ($parsed as $rowNumber => $row) {
            $studentId = $studentIds[$row['registration_no']] ?? null;

            if ($studentId === null) {
                $errors[] = $this->error($rowNumber, $row['record'], ["registration_no: {$row['registration_no']} not found"]);

                continue;
            }

            $enrollmentId = $enrollments[$studentId.':'.$row['examination_course_id']] ?? null;
            if ($enrollmentId === null) {
                $errors[] = $this->error($rowNumber, $row['record'], ['registration_no: student is not enrolled in this course']);

                continue;
            }

            $valid[] = [
                'row' => $rowNumber,
                'enrollment_id' => $enrollmentId,
                'component_id' => $row['component_id'],
                'marks' => $row['marks'],
                'absent' => $row['absent'],
            ];
        }

        usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);

        return ['valid' => $valid, 'errors' => $errors];
    }

    private function error(int $rowNumber, array $record, array $messages): array
    {
        return ['row' => $rowNumber, 'raw' => MarkCsv::toRawLine($record), 'errors' => $messages];
    }
}
