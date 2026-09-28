<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarkResource;
use App\Models\Examination;
use App\Services\MarkEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarkController extends Controller
{
    /**
     * Create or update a single mark. Updating requires the version you read
     * (body `expected_version` or `If-Match` header); a stale version is a 409.
     */
    public function upsert(Request $request, Examination $examination, MarkEntryService $marks): JsonResponse
    {
        $data = $request->validate([
            'registration_no' => ['required', 'string', 'max:32'],
            'course_code' => ['required', 'string', 'max:32'],
            'component_code' => ['required', 'string', 'max:32'],
            'marks' => ['nullable', 'required_unless:absent,true', 'numeric', 'min:0'],
            'absent' => ['sometimes', 'boolean'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $expected = $data['expected_version'] ?? null;
        if ($expected === null && ($ifMatch = $request->header('If-Match')) !== null) {
            $expected = (int) trim($ifMatch, '"W/');
        }

        // Keep the raw string so "45.50" is validated exactly, not via float.
        $data['marks'] = $request->input('marks') === null ? null : (string) $request->input('marks');

        $mark = $marks->upsert($examination, $data, $expected, $this->actor($request));

        return (new MarkResource($mark))
            ->response()
            ->setStatusCode($mark->wasRecentlyCreated ? 201 : 200)
            ->header('ETag', '"'.$mark->version.'"');
    }

    /** Keyset-paginated marks sheet, filterable by course and student. */
    public function index(Request $request, Examination $examination): JsonResponse
    {
        $marks = DB::table('marks as m')
            ->join('enrollments as e', 'e.id', '=', 'm.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('examination_courses as ec', 'ec.id', '=', 'e.examination_course_id')
            ->join('courses as c', 'c.id', '=', 'ec.course_id')
            ->join('assessment_components as ac', 'ac.id', '=', 'm.assessment_component_id')
            ->where('m.examination_id', $examination->id)
            ->when($request->query('course_code'), fn ($q, $v) => $q->where('c.code', strtoupper($v)))
            ->when($request->query('registration_no'), fn ($q, $v) => $q->where('s.registration_no', strtoupper($v)))
            ->orderBy('m.id')
            ->select([
                'm.id', 's.registration_no', 'c.code as course_code', 'ac.code as component_code',
                'm.marks_obtained as marks', 'm.is_absent as absent', 'm.version', 'm.source',
                'm.mark_import_id', 'm.updated_by', 'm.updated_at',
            ])
            ->cursorPaginate(min((int) $request->query('per_page', 200), 1000));

        return response()->json($marks);
    }
}
