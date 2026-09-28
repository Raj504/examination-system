<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Services\StudentResultService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Student result lookup. Only published examinations are shown. */
class PortalController extends Controller
{
    public function show(Request $request, StudentResultService $results): View
    {
        $examCode = trim((string) $request->query('exam'));
        $registrationNo = trim((string) $request->query('reg'));

        if ($examCode === '' || $registrationNo === '') {
            return view('portal', ['notFound' => false]);
        }

        $exam = Examination::where('code', strtoupper($examCode))->first();
        $result = ($exam && $exam->isPublished()) ? $results->find($exam, $registrationNo) : null;

        if ($result === null) {
            return view('portal', ['notFound' => true]);
        }

        return view('result', ['result' => $result, 'backUrl' => route('portal')]);
    }
}
