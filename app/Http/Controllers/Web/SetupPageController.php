<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Programme;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Web page for the master data: programmes, courses and students. */
class SetupPageController extends Controller
{
    public function show(): View
    {
        return view('setup', [
            'programmes' => Programme::orderBy('code')->get(),
            'courses' => Course::with('programme')->orderBy('code')->get(),
            'studentCount' => Student::count(),
        ]);
    }

    public function storeProgramme(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Programme::updateOrCreate(['code' => strtoupper($data['code'])], ['name' => $data['name']]);

        return back()->with('success', 'Programme saved.');
    }

    public function storeCourse(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'programme_code' => ['required', 'exists:programmes,code'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'credits' => ['required', 'integer', 'min:0', 'max:40'],
        ]);

        Course::updateOrCreate(
            ['code' => strtoupper($data['code'])],
            [
                'programme_id' => Programme::where('code', $data['programme_code'])->value('id'),
                'title' => $data['title'],
                'credits' => $data['credits'],
            ],
        );

        return back()->with('success', 'Course saved.');
    }

    /** Textarea with one student per line: "registration_no, name, programme_code". */
    public function storeStudents(Request $request): RedirectResponse
    {
        $request->validate(['students' => ['required', 'string']]);

        $programmes = Programme::pluck('id', 'code');
        $rows = [];
        $problems = [];

        foreach (preg_split('/\r?\n/', trim($request->input('students'))) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            [$registrationNo, $name, $programmeCode] = array_pad(array_map('trim', explode(',', $line)), 3, '');
            $programmeId = $programmes[strtoupper($programmeCode)] ?? null;

            if ($registrationNo === '' || $name === '' || $programmeId === null) {
                $problems[] = 'Line '.($i + 1).': expected "registration_no, name, programme_code" with an existing programme.';

                continue;
            }

            $rows[] = [
                'registration_no' => strtoupper($registrationNo),
                'name' => $name,
                'programme_id' => $programmeId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($problems !== []) {
            return back()->withInput()->with('error', implode(' ', array_slice($problems, 0, 10)));
        }

        // Insert new students, update existing ones (matched by registration number).
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('students')->upsert($chunk, ['registration_no'], ['name', 'programme_id', 'updated_at']);
        }

        return back()->with('success', count($rows).' student(s) saved.');
    }
}
