<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\Course;
use App\Models\Examination;
use App\Models\ExaminationCourse;
use Illuminate\Support\Facades\DB;

final class ExaminationStructureService
{
    /**
     * Adds a course offering with its assessment components to a DRAFT
     * examination, or replaces the components of an existing offering.
     *
     * @param  list<array{code: string, name: string, max_marks: float|int|string, weight: float|int|string, min_pass_marks?: float|int|string|null}>  $components
     */
    public function upsertCourse(Examination $exam, string $courseCode, float $passPercentage, array $components): ExaminationCourse
    {
        $weight = array_sum(array_map(fn ($c) => (float) $c['weight'], $components));
        if (abs($weight - 100) > 0.001) {
            throw AppException::unprocessable('Component weights must sum to 100.', 'invalid_component_weights', ['sum' => $weight]);
        }

        $codes = array_map(fn ($c) => strtoupper($c['code']), $components);
        if (count($codes) !== count(array_unique($codes))) {
            throw AppException::unprocessable('Component codes must be unique within a course.', 'duplicate_component_code');
        }

        foreach ($components as $c) {
            if (isset($c['min_pass_marks']) && (float) $c['min_pass_marks'] > (float) $c['max_marks']) {
                throw AppException::unprocessable("Component {$c['code']}: min_pass_marks exceeds max_marks.", 'invalid_component');
            }
        }

        $course = Course::where('code', $courseCode)->first()
            ?? throw AppException::unprocessable("Unknown course {$courseCode}.", 'unknown_course');

        $offering = DB::transaction(function () use ($exam, $course, $passPercentage, $components) {
            $locked = Examination::whereKey($exam->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isStructureEditable()) {
                throw AppException::conflict(
                    'Examination structure can only be changed while in draft.',
                    'structure_locked',
                    ['current_status' => $locked->status],
                );
            }

            $offering = ExaminationCourse::updateOrCreate(
                ['examination_id' => $locked->id, 'course_id' => $course->id],
                ['pass_percentage' => $passPercentage],
            );

            $offering->components()->delete();
            foreach ($components as $c) {
                $offering->components()->create([
                    'code' => strtoupper($c['code']),
                    'name' => $c['name'],
                    'max_marks' => $c['max_marks'],
                    'weight' => $c['weight'],
                    'min_pass_marks' => $c['min_pass_marks'] ?? null,
                ]);
            }

            return $offering;
        });

        ExaminationCatalog::forget($exam->id);

        return $offering->load('course', 'components');
    }
}
