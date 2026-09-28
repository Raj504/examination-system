<?php

namespace App\Services;

use App\Models\Examination;
use Illuminate\Support\Facades\DB;

/**
 * Reads one student's computed result for an examination
 * (used by the API, the staff pages and the student lookup page).
 */
class StudentResultService
{
    /** Returns null when the student has no result in this examination. */
    public function find(Examination $examination, string $registrationNo): ?array
    {
        $student = DB::table('students')
            ->where('registration_no', strtoupper(trim($registrationNo)))
            ->first(['id', 'registration_no', 'name']);

        if ($student === null) {
            return null;
        }

        $result = DB::table('examination_results')
            ->where('examination_id', $examination->id)
            ->where('student_id', $student->id)
            ->first();

        if ($result === null) {
            return null;
        }

        $courses = DB::table('course_results as cr')
            ->join('examination_courses as ec', 'ec.id', '=', 'cr.examination_course_id')
            ->join('courses as c', 'c.id', '=', 'ec.course_id')
            ->where('cr.examination_id', $examination->id)
            ->where('cr.student_id', $student->id)
            ->orderBy('c.code')
            ->get(['c.code', 'c.title', 'cr.credits', 'cr.percentage', 'cr.grade', 'cr.grade_point', 'cr.status', 'cr.remarks'])
            ->map(fn ($course) => [
                ...(array) $course,
                'remarks' => $course->remarks ? json_decode($course->remarks, true) : [],
            ])
            ->all();

        return [
            'examination' => [
                'code' => $examination->code,
                'name' => $examination->name,
                'published_at' => $examination->published_at?->toIso8601String(),
            ],
            'student' => ['registration_no' => $student->registration_no, 'name' => $student->name],
            'status' => $result->status,
            'sgpa' => $result->sgpa,
            'credits_attempted' => $result->credits_attempted,
            'credits_earned' => $result->credits_earned,
            'courses' => $courses,
        ];
    }
}
