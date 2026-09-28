<?php

namespace App\Console\Commands;

use App\Models\Examination;
use App\Services\ExaminationCatalog;
use App\Services\ExaminationLifecycle;
use App\Services\ExaminationStructureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates a realistic, large data set for load-testing the pipeline:
 * students, courses, an examination with 3 weighted components per course,
 * and enrollments of every student in every course. Uses set-based inserts,
 * so 100k students take seconds, not minutes.
 */
class DemoSeed extends Command
{
    protected $signature = 'exams:demo-seed
        {--students=20000 : Number of students}
        {--courses=2 : Courses in the examination}
        {--exam=DEMO-2026 : Examination code}';

    protected $description = 'Seed a large demo examination ready for marks entry';

    public function handle(ExaminationStructureService $structure, ExaminationLifecycle $lifecycle): int
    {
        $students = (int) $this->option('students');
        $courseCount = (int) $this->option('courses');
        $examCode = strtoupper((string) $this->option('exam'));

        if (Examination::where('code', $examCode)->exists()) {
            $this->error("Examination {$examCode} already exists.");

            return self::FAILURE;
        }

        $now = now();
        $programmeId = DB::table('programmes')->where('code', 'DEMO')->value('id')
            ?? DB::table('programmes')->insertGetId(['code' => 'DEMO', 'name' => 'Demo Programme', 'created_at' => $now, 'updated_at' => $now]);

        $this->info("Creating {$students} students...");
        $bar = $this->output->createProgressBar($students);
        for ($offset = 0; $offset < $students; $offset += 5000) {
            $rows = [];
            for ($i = $offset + 1; $i <= min($offset + 5000, $students); $i++) {
                $rows[] = [
                    'programme_id' => $programmeId,
                    'registration_no' => sprintf('D%07d', $i),
                    'name' => "Demo Student {$i}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('students')->insertOrIgnore($rows);
            $bar->advance(count($rows));
        }
        $bar->finish();
        $this->newLine();

        $exam = Examination::create(['code' => $examCode, 'name' => "Demo examination {$examCode}", 'academic_year' => '2026-27']);

        $courseCodes = [];
        for ($c = 1; $c <= $courseCount; $c++) {
            $code = sprintf('%s-C%02d', $examCode, $c);
            DB::table('courses')->insertOrIgnore([
                'programme_id' => $programmeId, 'code' => $code, 'title' => "Demo Course {$c}",
                'credits' => [4, 3, 3, 2][($c - 1) % 4], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $structure->upsertCourse($exam, $code, 40, [
                ['code' => 'INT', 'name' => 'Internal', 'max_marks' => 20, 'weight' => 20],
                ['code' => 'MID', 'name' => 'Mid term', 'max_marks' => 30, 'weight' => 30],
                ['code' => 'END', 'name' => 'End term', 'max_marks' => 100, 'weight' => 50, 'min_pass_marks' => 35],
            ]);
            $courseCodes[] = $code;
        }

        $this->info('Enrolling students...');
        foreach (ExaminationCatalog::for($exam->id)->all() as $course) {
            // Set-based: one INSERT ... SELECT per course instead of N round trips.
            // Registration numbers are zero-padded, so a string range selects the first N.
            DB::statement(
                'INSERT INTO enrollments (examination_id, examination_course_id, student_id, created_at, updated_at)
                 SELECT ?, ?, id, ?, ? FROM students
                 WHERE programme_id = ? AND registration_no BETWEEN ? AND ?',
                [$exam->id, $course['id'], $now, $now, $programmeId, 'D0000001', sprintf('D%07d', $students)],
            );
        }

        $lifecycle->openMarksEntry($exam);

        $enrollments = DB::table('enrollments')->where('examination_id', $exam->id)->count();
        $this->info("Examination {$examCode} (id {$exam->id}) is open for marks entry: {$enrollments} enrollments, "
            .($enrollments * 3).' marks expected.');
        $this->line("Next: php artisan exams:generate-csv {$exam->id}");

        return self::SUCCESS;
    }
}
