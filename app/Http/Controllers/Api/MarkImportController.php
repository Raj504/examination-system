<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarkImportResource;
use App\Models\Examination;
use App\Models\MarkImport;
use App\Services\Imports\MarkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarkImportController extends Controller
{
    public function __construct(private readonly MarkImportService $imports) {}

    /**
     * Accepts a CSV (registration_no, course_code, component_code, marks) and
     * returns 202 immediately; processing happens on the queue.
     */
    public function store(Request $request, Examination $examination): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('exams.imports.max_file_kb')],
        ]);

        ['import' => $import, 'created' => $created] = $this->imports->create(
            $examination,
            $request->file('file'),
            $this->actor($request),
        );

        $response = (new MarkImportResource($import))
            ->additional(['meta' => ['duplicate_of_existing_upload' => ! $created]])
            ->response()
            ->setStatusCode($created ? 202 : 200);

        // Only on 202: PHP's SAPI rewrites any 200 carrying `Location` into a 302.
        $header = $created ? 'Location' : 'Content-Location';

        return $response->header($header, url("/api/v1/mark-imports/{$import->id}"));
    }

    public function index(Request $request, Examination $examination): JsonResponse
    {
        $imports = $examination->markImports()->orderByDesc('created_at')->cursorPaginate(50);

        return MarkImportResource::collection($imports)->response();
    }

    public function show(MarkImport $markImport): MarkImportResource
    {
        return new MarkImportResource($markImport);
    }

    public function retry(MarkImport $markImport): JsonResponse
    {
        return (new MarkImportResource($this->imports->retry($markImport)))->response()->setStatusCode(202);
    }

    public function errors(Request $request, MarkImport $markImport): JsonResponse
    {
        $errors = $markImport->errors()
            ->orderBy('row_number')
            ->orderBy('id')
            ->select(['id', 'row_number', 'raw', 'errors'])
            ->cursorPaginate(min((int) $request->query('per_page', 100), 1000));

        return response()->json($errors);
    }

    /** Streams all row errors as a CSV the operator can fix and re-upload. */
    public function errorsCsv(MarkImport $markImport): StreamedResponse
    {
        return response()->streamDownload(function () use ($markImport) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row_number', 'errors', 'raw'], escape: '\\');

            foreach ($markImport->errors()->orderBy('row_number')->orderBy('id')->lazy(2000) as $error) {
                fputcsv($out, [$error->row_number, implode('; ', $error->errors), $error->raw], escape: '\\');
            }

            fclose($out);
        }, "import-{$markImport->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }
}
