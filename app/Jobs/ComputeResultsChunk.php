<?php

namespace App\Jobs;

use App\Models\Examination;
use App\Models\ResultRun;
use App\Services\ExaminationCatalog;
use App\Services\MarkParser;
use App\Services\ResultCalculator;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Computes course results and SGPA for students in [fromStudentId, toStudentId].
 *
 * Marks are locked while a run is in progress, so the input is immutable and
 * the output deterministic: results are UPSERTed by natural key and tagged
 * with the run id, which makes retries and duplicate deliveries harmless.
 */
class ComputeResultsChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(
        public readonly int $runId,
        public readonly int $fromStudentId,
        public readonly int $toStudentId,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = ResultRun::find($this->runId);
        if ($run === null || $run->status !== 'processing') {
            return;
        }

        $examId = $run->examination_id;
        if ((int) Examination::whereKey($examId)->value('current_result_run_id') !== $run->id) {
            return; // superseded by a newer run
        }

        $catalog = ExaminationCatalog::for($examId);
        $courses = $catalog->coursesById();
        $calculator = ResultCalculator::fromConfig();

        $enrollments = DB::table('enrollments')
            ->where('examination_id', $examId)
            ->whereBetween('student_id', [$this->fromStudentId, $this->toStudentId])
            ->orderBy('student_id')
            ->orderBy('id')
            ->get(['id', 'student_id', 'examination_course_id']);

        // $marks[enrollment id][component id] = ['absent' => bool, 'marks' => hundredths]
        $marks = [];
        foreach ($enrollments->pluck('id')->chunk(1000) as $ids) {
            $rows = DB::table('marks')
                ->whereIn('enrollment_id', $ids->all())
                ->get(['enrollment_id', 'assessment_component_id', 'marks_obtained', 'is_absent']);

            foreach ($rows as $row) {
                $marks[$row->enrollment_id][$row->assessment_component_id] = [
                    'absent' => (bool) $row->is_absent,
                    'marks' => (int) MarkParser::fromDatabase($row->marks_obtained),
                ];
            }
        }

        $now = now();
        $courseRows = [];
        $perStudent = [];

        foreach ($enrollments as $enrollment) {
            $course = $courses[$enrollment->examination_course_id];
            $result = $calculator->courseResult(
                array_values($course['components']),
                $marks[$enrollment->id] ?? [],
                $course['pass_percentage'],
            );

            $courseRows[] = [
                'examination_id' => $examId,
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'examination_course_id' => $enrollment->examination_course_id,
                'percentage' => $result['percentage'],
                'grade' => $result['grade'],
                'grade_point' => $result['grade_point'],
                'credits' => $course['credits'],
                'status' => $result['status'],
                'remarks' => $result['remarks'] === [] ? null : json_encode($result['remarks']),
                'result_run_id' => $run->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $perStudent[$enrollment->student_id][] = [
                'credits' => $course['credits'],
                'status' => $result['status'],
                'grade_point' => $result['grade_point'],
            ];
        }

        $examRows = [];
        foreach ($perStudent as $studentId => $courseResults) {
            $result = $calculator->examinationResult($courseResults);
            $examRows[] = [
                'examination_id' => $examId,
                'student_id' => $studentId,
                'credits_attempted' => $result['credits_attempted'],
                'credits_earned' => $result['credits_earned'],
                'sgpa' => $result['sgpa'],
                'status' => $result['status'],
                'result_run_id' => $run->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($courseRows, $examRows) {
            foreach (array_chunk($courseRows, 500) as $batch) {
                DB::table('course_results')->upsert(
                    $batch,
                    ['enrollment_id'],
                    ['percentage', 'grade', 'grade_point', 'credits', 'status', 'remarks', 'result_run_id', 'updated_at'],
                );
            }

            foreach (array_chunk($examRows, 500) as $batch) {
                DB::table('examination_results')->upsert(
                    $batch,
                    ['examination_id', 'student_id'],
                    ['credits_attempted', 'credits_earned', 'sgpa', 'status', 'result_run_id', 'updated_at'],
                );
            }
        }, attempts: 3);
    }
}
