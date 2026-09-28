<?php

namespace Tests\Unit;

use App\Services\ResultCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResultCalculatorTest extends TestCase
{
    private ResultCalculator $calculator;

    /** MID: 30 marks worth 30%. END: 70 marks worth 70%, must score at least 28. (Marks in hundredths.) */
    private array $components = [
        ['id' => 1, 'code' => 'MID', 'max' => 3000, 'weight' => 30.0, 'min_pass' => null],
        ['id' => 2, 'code' => 'END', 'max' => 7000, 'weight' => 70.0, 'min_pass' => 2800],
    ];

    protected function setUp(): void
    {
        $this->calculator = new ResultCalculator([
            ['min' => 90, 'grade' => 'O', 'point' => 10],
            ['min' => 80, 'grade' => 'A+', 'point' => 9],
            ['min' => 70, 'grade' => 'A', 'point' => 8],
            ['min' => 60, 'grade' => 'B+', 'point' => 7],
            ['min' => 50, 'grade' => 'B', 'point' => 6],
            ['min' => 45, 'grade' => 'C', 'point' => 5],
            ['min' => 40, 'grade' => 'P', 'point' => 4],
            ['min' => 0, 'grade' => 'F', 'point' => 0],
        ]);
    }

    /** Build the marks array. Pass an int for marks (hundredths), 'AB' for absent, null for "no mark". */
    private function course(int|string|null $mid, int|string|null $end): array
    {
        $marks = [];
        foreach ([1 => $mid, 2 => $end] as $componentId => $value) {
            if ($value === 'AB') {
                $marks[$componentId] = ['absent' => true, 'marks' => 0];
            } elseif ($value !== null) {
                $marks[$componentId] = ['absent' => false, 'marks' => $value];
            }
        }

        return $this->calculator->courseResult($this->components, $marks, 40.0);
    }

    public static function gradeBoundaries(): array
    {
        // [mid, end, expected percentage, expected grade]
        return [
            'perfect' => [3000, 7000, 100.0, 'O'],
            'exactly 90' => [2700, 6300, 90.0, 'O'],
            'just below 90' => [2700, 6299, 89.99, 'A+'],
            'exactly pass' => [1200, 2800, 40.0, 'P'],
            'half marks' => [1550, 3450, 50.0, 'B'],
        ];
    }

    #[DataProvider('gradeBoundaries')]
    public function test_weighted_percentage_and_grade(int $mid, int $end, float $percentage, string $grade): void
    {
        $result = $this->course($mid, $end);

        $this->assertSame(ResultCalculator::PASS, $result['status']);
        $this->assertEqualsWithDelta($percentage, $result['percentage'], 0.0001);
        $this->assertSame($grade, $result['grade']);
    }

    public function test_below_pass_percentage_fails(): void
    {
        $result = $this->course(1000, 2900); // 10 + 29 = 39%

        $this->assertSame(ResultCalculator::FAIL, $result['status']);
        $this->assertSame('F', $result['grade']);
        $this->assertSame(0.0, $result['grade_point']);
        $this->assertContains('below_pass_percentage', $result['remarks']);
    }

    public function test_component_minimum_fails_even_with_good_total(): void
    {
        $result = $this->course(3000, 2700); // 30 + 27 = 57%, but END is below its minimum of 28

        $this->assertSame(ResultCalculator::FAIL, $result['status']);
        $this->assertSame(57.0, $result['percentage']);
        $this->assertContains('below_component_minimum:END', $result['remarks']);
    }

    public function test_missing_mark_means_withheld(): void
    {
        $result = $this->course(3000, null);

        $this->assertSame(ResultCalculator::WITHHELD, $result['status']);
        $this->assertNull($result['percentage']);
        $this->assertNull($result['grade']);
        $this->assertSame(['missing_marks:END'], $result['remarks']);
    }

    public function test_absent_everywhere_is_absent(): void
    {
        $result = $this->course('AB', 'AB');

        $this->assertSame(ResultCalculator::ABSENT, $result['status']);
        $this->assertSame('AB', $result['grade']);
    }

    public function test_absent_in_one_component_counts_as_zero(): void
    {
        $result = $this->course('AB', 7000); // 0 + 70

        $this->assertSame(ResultCalculator::PASS, $result['status']);
        $this->assertSame(70.0, $result['percentage']);
        $this->assertSame('A', $result['grade']);
        $this->assertContains('absent:MID', $result['remarks']);
    }

    public function test_sgpa_is_weighted_by_credits(): void
    {
        $result = $this->calculator->examinationResult([
            ['credits' => 4, 'status' => ResultCalculator::PASS, 'grade_point' => 10],
            ['credits' => 3, 'status' => ResultCalculator::PASS, 'grade_point' => 7],
        ]);

        $this->assertSame(ResultCalculator::PASS, $result['status']);
        $this->assertSame(8.71, $result['sgpa']); // (4x10 + 3x7) / 7
        $this->assertSame(7, $result['credits_earned']);
    }

    public function test_examination_fails_if_any_course_fails(): void
    {
        $result = $this->calculator->examinationResult([
            ['credits' => 4, 'status' => ResultCalculator::PASS, 'grade_point' => 10],
            ['credits' => 3, 'status' => ResultCalculator::FAIL, 'grade_point' => 0],
        ]);

        $this->assertSame(ResultCalculator::FAIL, $result['status']);
        $this->assertSame(5.71, $result['sgpa']);
        $this->assertSame(4, $result['credits_earned']);
        $this->assertSame(7, $result['credits_attempted']);
    }

    public function test_examination_withheld_if_any_course_withheld(): void
    {
        $result = $this->calculator->examinationResult([
            ['credits' => 4, 'status' => ResultCalculator::PASS, 'grade_point' => 10],
            ['credits' => 3, 'status' => ResultCalculator::WITHHELD, 'grade_point' => 0],
        ]);

        $this->assertSame(ResultCalculator::WITHHELD, $result['status']);
        $this->assertNull($result['sgpa']);
    }
}
