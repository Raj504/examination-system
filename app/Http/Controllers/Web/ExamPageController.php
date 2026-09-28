<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Examination;
use App\Models\MarkImport;
use App\Services\EnrollmentService;
use App\Services\ExaminationCatalog;
use App\Services\ExaminationLifecycle;
use App\Services\ExaminationStructureService;
use App\Services\Imports\MarkImportService;
use App\Services\MarkEntryService;
use App\Services\MarkParser;
use App\Services\ResultProcessingService;
use App\Services\StudentResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Web pages for examinations. Every action calls the same services as the
 * API, so all business rules live in one place (app/Services).
 *
 * Business-rule errors (AppException) are shown as a red message on the
 * previous page - see bootstrap/app.php.
 */
class ExamPageController extends Controller
{
    private const ACTOR = 'web';

    public function index(): View
    {
        return view('exams.index', [
            'exams' => Examination::orderByDesc('id')->get(),
            'academicYears' => $this->academicYears(),
            'currentAcademicYear' => $this->currentAcademicYear(),
        ]);
    }

    /** Choices for the "Academic year" dropdown: 2 years back to 3 years ahead, e.g. "2026-27". */
    private function academicYears(): array
    {
        $startYear = (int) substr($this->currentAcademicYear(), 0, 4);
        $years = [];
        for ($year = $startYear - 2; $year <= $startYear + 3; $year++) {
            $years[] = $year.'-'.substr((string) ($year + 1), 2);
        }

        return $years;
    }

    /** The academic year starts in July: Sep 2026 -> "2026-27", Mar 2026 -> "2025-26". */
    private function currentAcademicYear(): string
    {
        $startYear = now()->month >= 7 ? now()->year : now()->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), 2);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:examinations,code'],
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'in:'.implode(',', $this->academicYears())],
        ]);

        $exam = Examination::create([...$data, 'code' => strtoupper($data['code'])]);

        return redirect()->route('exams.show', $exam)->with('success', 'Examination created. Now add its courses.');
    }

    public function show(Request $request, Examination $exam): View
    {
        $marks = DB::table('marks as m')
            ->join('enrollments as e', 'e.id', '=', 'm.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('examination_courses as ec', 'ec.id', '=', 'e.examination_course_id')
            ->join('courses as c', 'c.id', '=', 'ec.course_id')
            ->join('assessment_components as ac', 'ac.id', '=', 'm.assessment_component_id')
            ->where('m.examination_id', $exam->id)
            ->when($request->query('student'), fn ($q, $reg) => $q->where('s.registration_no', strtoupper(trim($reg))))
            ->orderBy('m.id')
            ->select(['m.id', 's.registration_no', 'c.code as course_code', 'ac.code as component_code', 'm.marks_obtained', 'm.is_absent', 'm.version'])
            ->simplePaginate(50, ['*'], 'marks_page')
            ->withQueryString();

        $results = null;
        if ($exam->hasResults()) {
            $results = DB::table('examination_results as r')
                ->join('students as s', 's.id', '=', 'r.student_id')
                ->where('r.examination_id', $exam->id)
                ->orderBy('s.registration_no')
                ->select(['s.registration_no', 's.name', 'r.sgpa', 'r.credits_attempted', 'r.credits_earned', 'r.status'])
                ->simplePaginate(50, ['*'], 'results_page')
                ->withQueryString();
        }

        $imports = $exam->markImports()->latest()->limit(10)->get();
        $latestRun = $exam->resultRuns()->latest('id')->first();
        $courses = array_values(ExaminationCatalog::for($exam->id)->all());

        return view('exams.show', [
            'exam' => $exam,
            'actions' => $this->actionsFor($exam),
            'nextStep' => $this->nextStep($exam, $courses),
            'courses' => $courses,
            'enrolledCounts' => DB::table('enrollments')->where('examination_id', $exam->id)
                ->groupBy('examination_course_id')->selectRaw('examination_course_id, COUNT(*) AS total')
                ->pluck('total', 'examination_course_id'),
            'catalogCourses' => $exam->isStructureEditable() ? Course::orderBy('code')->get() : collect(),
            'marks' => $marks,
            'imports' => $imports,
            'latestRun' => $latestRun,
            'results' => $results,
            // Reload the page automatically while an upload or a result run is still working.
            'autoRefresh' => $imports->contains(fn ($i) => in_array($i->status, MarkImport::ACTIVE_STATUSES, true))
                || $latestRun?->status === 'processing',
        ]);
    }

    /** One sentence telling the user what to do next. */
    private function nextStep(Examination $exam, array $courses): string
    {
        switch ($exam->status) {
            case Examination::DRAFT:
                return $courses === []
                    ? 'Add the courses of this exam (section 1).'
                    : 'Enroll students (section 2), then click "Open marks entry".';
            case Examination::MARKS_ENTRY:
                return 'Enter marks one by one or upload a CSV file (section 3). When all marks are in, click "Lock marks".';
            case Examination::MARKS_LOCKED:
                return 'Click "Process results" to calculate the results.';
            case Examination::PROCESSING:
                return 'Results are being calculated. This page refreshes by itself.';
            case Examination::RESULTS_READY:
                return 'Check the results (section 4). Click "Publish results" when they are correct, or "Unlock marks" to fix a mark.';
            default:
                return 'Results are published. Students can see them on the "Student result lookup" page.';
        }
    }

    /** Buttons shown at the top of the page, e.g. ['lock' => 'Lock marks']. */
    private function actionsFor(Examination $exam): array
    {
        $actions = [];

        if ($exam->status === Examination::PROCESSING) {
            return $actions; // the background run moves the examination on by itself
        }

        if ($exam->canMoveTo(Examination::MARKS_ENTRY)) {
            if ($exam->status === Examination::DRAFT) {
                $actions['open'] = 'Open marks entry';
            } else {
                $actions['unlock'] = 'Unlock marks (to correct them)';
            }
        }
        if ($exam->canMoveTo(Examination::MARKS_LOCKED)) {
            $actions['lock'] = 'Lock marks';
        }
        if ($exam->canMoveTo(Examination::PROCESSING)) {
            $actions['process'] = 'Process results';
        }
        if ($exam->canMoveTo(Examination::PUBLISHED)) {
            $actions['publish'] = 'Publish results';
        }

        return $actions;
    }

    public function changeStatus(Request $request, Examination $exam, ExaminationLifecycle $lifecycle, ResultProcessingService $results): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:open,lock,unlock,process,publish']])['action'];

        switch ($action) {
            case 'open':
                $lifecycle->openMarksEntry($exam);
                $message = 'Marks entry is open.';
                break;
            case 'lock':
                $lifecycle->lockMarks($exam);
                $message = 'Marks are locked.';
                break;
            case 'unlock':
                $lifecycle->unlockMarks($exam);
                $message = 'Marks are unlocked for corrections.';
                break;
            case 'process':
                $results->start($exam, self::ACTOR);
                $message = 'Result processing started. This page refreshes until it finishes.';
                break;
            default: // publish
                $lifecycle->publish($exam);
                $message = 'Results are published.';
        }

        return back()->with('success', $message);
    }

    /** Adds a course to the exam. Components come from the rows of the form; empty rows are ignored. */
    public function addCourse(Request $request, Examination $exam, ExaminationStructureService $structure): RedirectResponse
    {
        $data = $request->validate([
            'course_code' => ['required', 'string'],
            'pass_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'components' => ['required', 'array'],
        ]);

        // Keep only the rows where a code was typed.
        $components = [];
        foreach ($data['components'] as $row) {
            $code = trim($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $components[] = [
                'code' => $code,
                'name' => trim($row['name'] ?? '') ?: $code,
                'max_marks' => $row['max_marks'] ?? null,
                'weight' => $row['weight'] ?? null,
            ];
        }

        Validator::make(['components' => $components], [
            'components' => ['required', 'array', 'min:1'],
            'components.*.code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'components.*.max_marks' => ['required', 'numeric', 'gt:0', 'max:9999.99'],
            'components.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
        ], [
            'components.required' => 'Fill in at least one component.',
            'components.*.code.regex' => 'Component codes can only contain letters, numbers, - and _.',
            'components.*.max_marks.required' => 'Every component needs "Marked out of".',
            'components.*.weight.required' => 'Every component needs a weight.',
        ])->validate();

        $structure->upsertCourse($exam, strtoupper($data['course_code']), (float) $data['pass_percentage'], $components);

        return back()->with('success', "Course {$data['course_code']} saved.");
    }

    public function enroll(Request $request, Examination $exam, EnrollmentService $enrollments): RedirectResponse
    {
        $data = $request->validate([
            'course_code' => ['required', 'string'],
            'registration_nos' => ['required', 'string'],
        ]);

        $numbers = preg_split('/[\s,;]+/', trim($data['registration_nos']), -1, PREG_SPLIT_NO_EMPTY);
        $result = $enrollments->enroll($exam, $data['course_code'], $numbers);

        $redirect = back()->with('success', "{$result['enrolled']} enrolled, {$result['already_enrolled']} were already enrolled.");

        if ($result['unknown_registration_nos'] !== []) {
            $redirect->with('error', 'Not found (add them on the setup page first): '.implode(', ', array_slice($result['unknown_registration_nos'], 0, 20)));
        }

        return $redirect;
    }

    /** Adds a new mark. Existing marks are changed on the edit page. */
    public function storeMark(Request $request, Examination $exam, MarkEntryService $marks): RedirectResponse
    {
        $data = $request->validate([
            'registration_no' => ['required', 'string'],
            'component' => ['required', 'string'], // "CS101|MID" from the dropdown
            'marks' => ['required', 'string'],
        ]);
        [$data['course_code'], $data['component_code']] = array_pad(explode('|', $data['component'], 2), 2, '');

        try {
            $marks->upsert($exam, $this->markInput($data), null, self::ACTOR);
        } catch (AppException $e) {
            if ($e->errorCode === 'precondition_required') {
                return back()->withInput()->with('error', 'This mark already exists. Use "Edit" in the marks table to change it.');
            }
            throw $e;
        }

        return back()->with('success', 'Mark saved.');
    }

    public function editMark(Examination $exam, int $markId): View
    {
        return view('exams.edit-mark', ['exam' => $exam, 'mark' => $this->findMark($exam, $markId)]);
    }

    public function updateMark(Request $request, Examination $exam, int $markId, MarkEntryService $marks): RedirectResponse
    {
        $data = $request->validate([
            'marks' => ['required', 'string'],
            'version' => ['required', 'integer'],
        ]);
        $mark = $this->findMark($exam, $markId);

        try {
            $marks->upsert($exam, $this->markInput([
                'registration_no' => $mark->registration_no,
                'course_code' => $mark->course_code,
                'component_code' => $mark->component_code,
                'marks' => $data['marks'],
            ]), (int) $data['version'], self::ACTOR);
        } catch (AppException $e) {
            if ($e->errorCode === 'version_conflict') {
                // Someone saved this mark after we showed it. Show them the latest value.
                return redirect()->route('exams.marks.edit', [$exam, $markId])
                    ->with('error', 'Someone else changed this mark while you were editing. The latest value is shown below; check it and save again.');
            }
            throw $e;
        }

        return redirect()->route('exams.show', $exam)->with('success', 'Mark updated.');
    }

    public function uploadMarks(Request $request, Examination $exam, MarkImportService $imports): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('exams.imports.max_file_kb')],
        ]);

        $upload = $imports->create($exam, $request->file('file'), self::ACTOR);

        return back()->with('success', $upload['created']
            ? 'File uploaded. It is being processed; this page refreshes until it finishes.'
            : 'This exact file was already uploaded, so it was not processed again.');
    }

    public function importErrors(Examination $exam, MarkImport $import): View
    {
        abort_unless($import->examination_id === $exam->id, 404);

        return view('exams.import-errors', [
            'exam' => $exam,
            'import' => $import,
            'rejectedRows' => $import->errors()->orderBy('row_number')->paginate(100),
        ]);
    }

    public function studentResult(Examination $exam, string $registrationNo, StudentResultService $results): View
    {
        $result = $exam->hasResults() ? $results->find($exam, $registrationNo) : null;
        abort_if($result === null, 404);

        return view('result', ['result' => $result, 'backUrl' => route('exams.show', $exam)]);
    }

    /** Turns the "marks" text box ("45.5" or "AB") into the service input. */
    private function markInput(array $data): array
    {
        $absent = MarkParser::isAbsent($data['marks']);

        return [
            'registration_no' => $data['registration_no'],
            'course_code' => $data['course_code'],
            'component_code' => $data['component_code'],
            'absent' => $absent,
            'marks' => $absent ? null : trim($data['marks']),
        ];
    }

    private function findMark(Examination $exam, int $markId): object
    {
        $mark = DB::table('marks as m')
            ->join('enrollments as e', 'e.id', '=', 'm.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('examination_courses as ec', 'ec.id', '=', 'e.examination_course_id')
            ->join('courses as c', 'c.id', '=', 'ec.course_id')
            ->join('assessment_components as ac', 'ac.id', '=', 'm.assessment_component_id')
            ->where('m.examination_id', $exam->id)
            ->where('m.id', $markId)
            ->first(['m.id', 's.registration_no', 'c.code as course_code', 'ac.code as component_code', 'm.marks_obtained', 'm.is_absent', 'm.version']);

        abort_if($mark === null, 404);

        return $mark;
    }
}
