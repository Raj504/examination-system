<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExaminationResource;
use App\Models\Examination;
use App\Models\MarkImport;
use App\Services\EnrollmentService;
use App\Services\ExaminationCatalog;
use App\Services\ExaminationLifecycle;
use App\Services\ExaminationStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExaminationController extends Controller
{
    public function __construct(private readonly ExaminationLifecycle $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $exams = Examination::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->cursorPaginate(min((int) $request->query('per_page', 50), 200));

        return ExaminationResource::collection($exams)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:examinations,code'],
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:16'],
        ]);

        $exam = Examination::create([...$data, 'code' => strtoupper($data['code'])]);

        return (new ExaminationResource($exam->refresh()))->response()->setStatusCode(201);
    }

    public function show(Examination $examination): ExaminationResource
    {
        return new ExaminationResource($examination);
    }

    /** Structure of the examination: courses with their components. */
    public function courses(Examination $examination): JsonResponse
    {
        $courses = collect(ExaminationCatalog::for($examination->id)->all())->values()->map(fn ($c) => [
            'examination_course_id' => $c['id'],
            'course_code' => $c['code'],
            'credits' => $c['credits'],
            'pass_percentage' => $c['pass_percentage'],
            'components' => array_values(array_map(fn ($comp) => [
                'code' => $comp['code'],
                'max_marks' => $comp['max'] / 100,
                'weight' => $comp['weight'],
                'min_pass_marks' => $comp['min_pass'] === null ? null : $comp['min_pass'] / 100,
            ], $c['components'])),
        ]);

        return response()->json(['data' => $courses]);
    }

    /**
     * PUT /examinations/{e}/courses/{courseCode}, or PUT|POST /examinations/{e}/courses
     * with `course_code` in the body.
     */
    public function upsertCourse(Request $request, Examination $examination, ExaminationStructureService $structure, ?string $courseCode = null): JsonResponse
    {
        $data = $request->validate([
            'course_code' => [$courseCode === null ? 'required' : 'sometimes', 'string', 'max:32'],
            'pass_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.max_marks' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'components.*.weight' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'components.*.min_pass_marks' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ], [
            'course_code.required' => 'Say which course to add: put its code in the URL (PUT /examinations/{id}/courses/{courseCode}) or send "course_code" in the body. The course must exist in the catalogue (POST /courses).',
        ]);

        $offering = $structure->upsertCourse(
            $examination,
            strtoupper($courseCode ?? $data['course_code']),
            (float) ($data['pass_percentage'] ?? 40),
            $data['components'],
        );

        return response()->json(['data' => $offering]);
    }

    public function enroll(Request $request, Examination $examination, EnrollmentService $enrollments): JsonResponse
    {
        $data = $request->validate([
            'course_code' => ['required', 'string'],
            'registration_nos' => ['required', 'array', 'min:1', 'max:5000'],
            'registration_nos.*' => ['required', 'string', 'max:32'],
        ]);

        return response()->json([
            'data' => $enrollments->enroll($examination, $data['course_code'], $data['registration_nos']),
        ]);
    }

    public function openMarksEntry(Examination $examination): ExaminationResource
    {
        return new ExaminationResource($this->lifecycle->openMarksEntry($examination));
    }

    public function lockMarks(Examination $examination): ExaminationResource
    {
        return new ExaminationResource($this->lifecycle->lockMarks($examination));
    }

    public function unlockMarks(Examination $examination): ExaminationResource
    {
        return new ExaminationResource($this->lifecycle->unlockMarks($examination));
    }

    public function publish(Examination $examination): ExaminationResource
    {
        return new ExaminationResource($this->lifecycle->publish($examination));
    }

    /**
     * Marks-entry completeness: how many marks are expected (enrollments x
     * components) versus entered, per course. Used before locking.
     */
    public function progress(Examination $examination): JsonResponse
    {
        $catalog = ExaminationCatalog::for($examination->id)->coursesById();

        $enrolled = DB::table('enrollments')->where('examination_id', $examination->id)
            ->selectRaw('examination_course_id, COUNT(*) AS n')->groupBy('examination_course_id')
            ->pluck('n', 'examination_course_id');

        $entered = DB::table('marks')
            ->join('enrollments', 'enrollments.id', '=', 'marks.enrollment_id')
            ->where('marks.examination_id', $examination->id)
            ->selectRaw('enrollments.examination_course_id, COUNT(*) AS n')
            ->groupBy('enrollments.examination_course_id')
            ->pluck('n', 'examination_course_id');

        $courses = [];
        $totals = ['expected' => 0, 'entered' => 0];
        foreach ($catalog as $id => $course) {
            $expected = (int) ($enrolled[$id] ?? 0) * count($course['components']);
            $done = (int) ($entered[$id] ?? 0);
            $totals['expected'] += $expected;
            $totals['entered'] += $done;
            $courses[] = [
                'course_code' => $course['code'],
                'enrollments' => (int) ($enrolled[$id] ?? 0),
                'expected_marks' => $expected,
                'entered_marks' => $done,
                'missing_marks' => $expected - $done,
            ];
        }

        return response()->json(['data' => [
            'status' => $examination->status,
            'expected_marks' => $totals['expected'],
            'entered_marks' => $totals['entered'],
            'missing_marks' => $totals['expected'] - $totals['entered'],
            'active_imports' => $examination->markImports()->whereIn('status', MarkImport::ACTIVE_STATUSES)->count(),
            'courses' => $courses,
        ]]);
    }
}
