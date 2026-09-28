<?php

namespace Tests\Feature\Concerns;

use App\Models\Course;
use App\Models\Examination;
use App\Models\Programme;
use App\Models\Student;
use App\Services\EnrollmentService;
use App\Services\ExaminationLifecycle;
use App\Services\ExaminationStructureService;

trait BuildsExaminations
{
    /**
     * Two courses:
     *   CS101 (4 credits): MID 30 marks / 30%, END 70 marks / 70% (END min 28)
     *   CS102 (3 credits): LAB 100 marks / 100%
     * Students S001..S00n enrolled in both; examination opened for marks entry.
     */
    protected function examinationInMarksEntry(int $students = 3): Examination
    {
        $programme = Programme::create(['code' => 'BTECH', 'name' => 'B.Tech']);
        Course::create(['programme_id' => $programme->id, 'code' => 'CS101', 'title' => 'Programming', 'credits' => 4]);
        Course::create(['programme_id' => $programme->id, 'code' => 'CS102', 'title' => 'Lab', 'credits' => 3]);

        $regs = [];
        for ($i = 1; $i <= $students; $i++) {
            $reg = sprintf('S%03d', $i);
            Student::create(['programme_id' => $programme->id, 'registration_no' => $reg, 'name' => "Student {$i}"]);
            $regs[] = $reg;
        }

        $exam = Examination::create(['code' => 'SEM1-2026', 'name' => 'Semester 1', 'academic_year' => '2026-27']);

        $structure = app(ExaminationStructureService::class);
        $structure->upsertCourse($exam, 'CS101', 40, [
            ['code' => 'MID', 'name' => 'Mid term', 'max_marks' => 30, 'weight' => 30],
            ['code' => 'END', 'name' => 'End term', 'max_marks' => 70, 'weight' => 70, 'min_pass_marks' => 28],
        ]);
        $structure->upsertCourse($exam, 'CS102', 40, [
            ['code' => 'LAB', 'name' => 'Lab', 'max_marks' => 100, 'weight' => 100],
        ]);

        $enrollments = app(EnrollmentService::class);
        $enrollments->enroll($exam, 'CS101', $regs);
        $enrollments->enroll($exam, 'CS102', $regs);

        return app(ExaminationLifecycle::class)->openMarksEntry($exam);
    }

    protected function csv(array $rows, string $header = 'registration_no,course_code,component_code,marks'): string
    {
        return $header."\n".implode("\n", array_map(fn ($r) => implode(',', $r), $rows))."\n";
    }
}
