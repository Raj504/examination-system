<?php

namespace App\Console\Commands;

use App\Services\ExaminationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes a marks CSV covering every (enrollment x component) of an
 * examination, with a configurable share of deliberately invalid rows to
 * exercise the error-reporting path. Streams rows, so memory stays flat.
 */
class GenerateMarksCsv extends Command
{
    protected $signature = 'exams:generate-csv
        {examination : Examination id}
        {--path= : Output file (default storage/app/demo-marks-{id}.csv)}
        {--error-rate=0.005 : Fraction of rows made invalid}
        {--absent-rate=0.01 : Fraction of rows marked AB}';

    protected $description = 'Generate a large marks CSV for an examination';

    public function handle(): int
    {
        $examId = (int) $this->argument('examination');
        $path = $this->option('path') ?: storage_path("app/demo-marks-{$examId}.csv");
        $errorRate = (float) $this->option('error-rate');
        $absentRate = (float) $this->option('absent-rate');

        $courses = ExaminationCatalog::for($examId)->coursesById();
        if ($courses === []) {
            $this->error('Examination has no courses.');

            return self::FAILURE;
        }

        mt_srand($examId);
        $out = fopen($path, 'w');
        fputcsv($out, ['registration_no', 'course_code', 'component_code', 'marks'], escape: '\\');
        $rows = 0;
        $invalid = 0;

        DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.examination_id', $examId)
            ->select(['e.id', 'e.examination_course_id', 's.registration_no'])
            ->orderBy('e.id')
            ->chunkById(5000, function ($enrollments) use ($out, $courses, $errorRate, $absentRate, &$rows, &$invalid) {
                foreach ($enrollments as $e) {
                    $course = $courses[$e->examination_course_id];
                    foreach ($course['components'] as $component) {
                        $max = $component['max'];
                        $marks = number_format(mt_rand((int) ($max * 0.25), $max) / 100, 2, '.', '');

                        if (mt_rand() / mt_getrandmax() < $absentRate) {
                            $marks = 'AB';
                        }
                        if (mt_rand() / mt_getrandmax() < $errorRate) {
                            $marks = mt_rand(0, 1) ? number_format($max / 100 + 5, 2, '.', '') : 'N/A';
                            $invalid++;
                        }

                        fputcsv($out, [$e->registration_no, $course['code'], $component['code'], $marks], escape: '\\');
                        $rows++;
                    }
                }
            }, 'e.id', 'id');

        fclose($out);

        $this->info("Wrote {$rows} rows ({$invalid} intentionally invalid) to {$path}");

        return self::SUCCESS;
    }
}
