<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Programme;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Master data: programmes, courses and students. Writes are idempotent (safe to repeat)
 * upserts keyed by natural code, so they can be replayed safely.
 */
class CatalogController extends Controller
{
    public function programmes(): JsonResponse
    {
        return response()->json(['data' => Programme::orderBy('code')->get()]);
    }

    public function storeProgramme(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $programme = Programme::updateOrCreate(['code' => strtoupper($data['code'])], ['name' => $data['name']]);

        return response()->json(['data' => $programme], $programme->wasRecentlyCreated ? 201 : 200);
    }

    public function courses(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->with('programme:id,code')
            ->when($request->query('programme'), fn ($q, $code) => $q->whereHas('programme', fn ($p) => $p->where('code', strtoupper($code))))
            ->orderBy('code')
            ->cursorPaginate(min((int) $request->query('per_page', 100), 500));

        return response()->json($courses);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'programme_code' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'credits' => ['required', 'integer', 'min:0', 'max:40'],
        ]);

        $programmeId = Programme::where('code', strtoupper($data['programme_code']))->value('id')
            ?? throw AppException::unprocessable('Unknown programme.', 'unknown_programme');

        $course = Course::updateOrCreate(
            ['code' => strtoupper($data['code'])],
            [
                'programme_id' => $programmeId,
                'title' => $data['title'],
                'credits' => $data['credits'],
            ],
        );

        return response()->json(['data' => $course], $course->wasRecentlyCreated ? 201 : 200);
    }

    public function students(Request $request): JsonResponse
    {
        $students = Student::query()
            ->with('programme:id,code')
            ->when($request->query('programme'), fn ($q, $code) => $q->whereHas('programme', fn ($p) => $p->where('code', strtoupper($code))))
            ->orderBy('id')
            ->cursorPaginate(min((int) $request->query('per_page', 100), 500));

        return response()->json($students);
    }

    public function student(string $registrationNo): JsonResponse
    {
        $student = Student::with('programme:id,code,name')
            ->where('registration_no', strtoupper($registrationNo))
            ->firstOrFail();

        return response()->json(['data' => $student]);
    }

    /**
     * Bulk upsert of up to 1000 students per request, keyed by registration_no.
     */
    public function storeStudents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'students' => ['required', 'array', 'min:1', 'max:1000'],
            'students.*.registration_no' => ['required', 'string', 'max:32', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9_\-\/]+$/'],
            'students.*.name' => ['required', 'string', 'max:255'],
            'students.*.email' => ['nullable', 'email', 'max:255'],
            'students.*.programme_code' => ['required', 'string'],
        ]);

        $programmes = Programme::pluck('id', 'code');
        $now = now();
        $rows = [];
        $errors = [];

        foreach ($data['students'] as $i => $s) {
            $programmeId = $programmes[strtoupper($s['programme_code'])] ?? null;
            if ($programmeId === null) {
                $errors["students.{$i}.programme_code"] = ['Unknown programme.'];

                continue;
            }

            $rows[] = [
                'registration_no' => strtoupper($s['registration_no']),
                'name' => $s['name'],
                'email' => $s['email'] ?? null,
                'programme_id' => $programmeId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($errors !== []) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Unknown programme codes.', 'details' => $errors]], 422);
        }

        DB::table('students')->upsert($rows, ['registration_no'], ['name', 'email', 'programme_id', 'updated_at']);

        return response()->json(['data' => ['upserted' => count($rows)]]);
    }
}
