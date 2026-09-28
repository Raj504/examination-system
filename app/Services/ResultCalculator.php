<?php

namespace App\Services;

/**
 * The grading rules. Plain PHP with no database access, so every rule is
 * easy to read and easy to unit test (see tests/Unit/ResultCalculatorTest.php).
 *
 * All marks here are in hundredths (see MarkParser): 45.5 marks = 4550.
 *
 * Course rules
 *   - a component with no mark at all   -> "withheld" (result cannot be declared yet)
 *   - absent in every component         -> "absent", grade AB
 *   - percentage = sum of (marks / max marks x weight); absent counts as 0
 *   - below the pass percentage, or below a component's minimum -> "fail", grade F
 *   - otherwise "pass", with the grade from the grade scale (config/exams.php)
 *
 * Examination rules (one row per student)
 *   - any course withheld -> "withheld", no SGPA
 *   - SGPA = sum of (credits x grade point) / total credits
 *   - "pass" only if every course passed, otherwise "fail"
 */
class ResultCalculator
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const ABSENT = 'absent';

    public const WITHHELD = 'withheld';

    /** @var list<array{min: float, grade: string, point: float}> highest band first */
    private array $gradeScale;

    /** @param list<array{min: int|float, grade: string, point: int|float}> $gradeScale */
    public function __construct(array $gradeScale)
    {
        usort($gradeScale, fn ($a, $b) => $b['min'] <=> $a['min']);
        $this->gradeScale = $gradeScale;
    }

    public static function fromConfig(): self
    {
        return new self(config('exams.grade_scale'));
    }

    /**
     * Result of one student in one course.
     *
     * @param  list<array{id: int, code: string, max: int, weight: float, min_pass: ?int}>  $components  the course's components
     * @param  array<int, array{absent: bool, marks: int}>  $marks  the student's marks, keyed by component id
     * @return array{status: string, percentage: ?float, grade: ?string, grade_point: float, remarks: list<string>}
     */
    public function courseResult(array $components, array $marks, float $passPercentage): array
    {
        // 1. Every component needs a mark (or "absent") before we can grade.
        $missing = [];
        foreach ($components as $component) {
            if (! isset($marks[$component['id']])) {
                $missing[] = 'missing_marks:'.$component['code'];
            }
        }
        if ($missing) {
            return $this->course(self::WITHHELD, null, null, 0, $missing);
        }

        // 2. Add up the weighted percentage and note any problems.
        $percentage = 0.0;
        $remarks = [];
        $absentEverywhere = true;
        $failedAComponentMinimum = false;

        foreach ($components as $component) {
            $mark = $marks[$component['id']];

            if ($mark['absent']) {
                $remarks[] = 'absent:'.$component['code'];
            } else {
                $absentEverywhere = false;
            }

            $obtained = $mark['absent'] ? 0 : $mark['marks'];
            $percentage += $obtained / $component['max'] * $component['weight'];

            if ($component['min_pass'] !== null && $obtained < $component['min_pass']) {
                $remarks[] = 'below_component_minimum:'.$component['code'];
                $failedAComponentMinimum = true;
            }
        }

        if ($absentEverywhere) {
            return $this->course(self::ABSENT, 0.0, 'AB', 0, $remarks);
        }

        $percentage = round($percentage, 2);

        // 3. Pass or fail.
        if ($percentage < $passPercentage) {
            $remarks[] = 'below_pass_percentage';
        }
        if ($percentage < $passPercentage || $failedAComponentMinimum) {
            return $this->course(self::FAIL, $percentage, 'F', 0, $remarks);
        }

        $band = $this->gradeFor($percentage);

        return $this->course(self::PASS, $percentage, $band['grade'], $band['point'], $remarks);
    }

    /**
     * Overall result of one student in the examination.
     *
     * @param  list<array{credits: int, status: string, grade_point: float}>  $courses  the student's course results
     * @return array{status: string, credits_attempted: int, credits_earned: int, sgpa: ?float}
     */
    public function examinationResult(array $courses): array
    {
        $creditsAttempted = 0;
        $creditsEarned = 0;
        $points = 0.0;
        $statuses = [];

        foreach ($courses as $course) {
            $creditsAttempted += $course['credits'];
            $points += $course['credits'] * $course['grade_point'];
            $statuses[] = $course['status'];

            if ($course['status'] === self::PASS) {
                $creditsEarned += $course['credits'];
            }
        }

        if (in_array(self::WITHHELD, $statuses, true)) {
            $status = self::WITHHELD;
            $sgpa = null;
        } else {
            $allPassed = array_diff($statuses, [self::PASS]) === []; // no status other than "pass"
            $status = $allPassed ? self::PASS : self::FAIL;
            $sgpa = $creditsAttempted > 0 ? round($points / $creditsAttempted, 2) : null;
        }

        return [
            'status' => $status,
            'credits_attempted' => $creditsAttempted,
            'credits_earned' => $creditsEarned,
            'sgpa' => $sgpa,
        ];
    }

    /** @return array{grade: string, point: float} */
    private function gradeFor(float $percentage): array
    {
        foreach ($this->gradeScale as $band) {
            if ($percentage >= $band['min']) {
                return ['grade' => $band['grade'], 'point' => (float) $band['point']];
            }
        }

        return ['grade' => 'F', 'point' => 0.0];
    }

    private function course(string $status, ?float $percentage, ?string $grade, float $gradePoint, array $remarks): array
    {
        return [
            'status' => $status,
            'percentage' => $percentage,
            'grade' => $grade,
            'grade_point' => $gradePoint,
            'remarks' => $remarks,
        ];
    }
}
