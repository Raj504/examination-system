<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only snapshot of an examination's structure (courses, credits,
 * components) keyed for O(1) lookup by the hot paths: CSV validation and
 * result computation.
 *
 * The structure can only change while the examination is in DRAFT, and marks
 * can only be written after it leaves DRAFT, so the snapshot is safe to cache
 * across workers; it is invalidated explicitly on every structure change.
 */
final class ExaminationCatalog
{
    /**
     * @param  array<string, array{id: int, course_id: int, credits: int, pass_percentage: float, components: array<string, array{id: int, code: string, max: int, weight: float, min_pass: ?int}>}>  $courses  keyed by upper-case course code
     */
    private function __construct(
        public readonly int $examinationId,
        private readonly array $courses,
    ) {}

    public static function for(int $examinationId): self
    {
        $courses = Cache::remember(self::cacheKey($examinationId), now()->addHour(), fn () => self::load($examinationId));

        return new self($examinationId, $courses);
    }

    public static function forget(int $examinationId): void
    {
        Cache::forget(self::cacheKey($examinationId));
    }

    private static function cacheKey(int $examinationId): string
    {
        return "exam:{$examinationId}:catalog:v1";
    }

    private static function load(int $examinationId): array
    {
        $rows = DB::table('examination_courses as ec')
            ->join('courses as c', 'c.id', '=', 'ec.course_id')
            ->leftJoin('assessment_components as ac', 'ac.examination_course_id', '=', 'ec.id')
            ->where('ec.examination_id', $examinationId)
            ->orderBy('ec.id')
            ->orderBy('ac.id')
            ->get([
                'ec.id as ec_id', 'ec.course_id', 'ec.pass_percentage', 'c.code as course_code', 'c.credits',
                'ac.id as ac_id', 'ac.code as ac_code', 'ac.max_marks', 'ac.weight', 'ac.min_pass_marks',
            ]);

        $courses = [];
        foreach ($rows as $row) {
            $key = strtoupper($row->course_code);
            $courses[$key] ??= [
                'id' => (int) $row->ec_id,
                'course_id' => (int) $row->course_id,
                'code' => $row->course_code,
                'credits' => (int) $row->credits,
                'pass_percentage' => (float) $row->pass_percentage,
                'components' => [],
            ];

            if ($row->ac_id !== null) {
                $courses[$key]['components'][strtoupper($row->ac_code)] = [
                    'id' => (int) $row->ac_id,
                    'code' => $row->ac_code,
                    'max' => MarkParser::fromDatabase($row->max_marks),
                    'weight' => (float) $row->weight,
                    'min_pass' => MarkParser::fromDatabase($row->min_pass_marks),
                ];
            }
        }

        return $courses;
    }

    public function course(string $code): ?array
    {
        return $this->courses[strtoupper(trim($code))] ?? null;
    }

    public function courseById(int $examinationCourseId): ?array
    {
        foreach ($this->courses as $course) {
            if ($course['id'] === $examinationCourseId) {
                return $course;
            }
        }

        return null;
    }

    /** @return array<int, array> keyed by examination_course id */
    public function coursesById(): array
    {
        $byId = [];
        foreach ($this->courses as $course) {
            $byId[$course['id']] = $course;
        }

        return $byId;
    }

    public function isEmpty(): bool
    {
        return $this->courses === [];
    }

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->courses;
    }
}
