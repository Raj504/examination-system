<?php

namespace App\Jobs;

use App\Exceptions\AppException;
use App\Models\MarkImport;
use App\Models\MarkImportChunk;
use App\Services\ExaminationCatalog;
use App\Services\ExaminationLifecycle;
use App\Services\Imports\MarkCsv;
use App\Services\Imports\MarkRowValidator;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pass 2 of an import: validates and writes one slice of the file.
 *
 * Safe to run more than once (idempotent). The queue may deliver a job twice, which is fine because:
 *  - marks are UPSERTed on (enrollment_id, assessment_component_id);
 *  - this chunk's previous error rows are deleted and re-inserted;
 *  - chunk statistics are overwritten, never incremented;
 *  - all of the above commit in ONE transaction, so a crash mid-chunk leaves
 *    no partial trace and the retry starts from a clean slate.
 */
class ProcessMarkImportChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(
        public readonly string $importId,
        public readonly int $chunkIndex,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $import = MarkImport::find($this->importId);
        $chunk = MarkImportChunk::where('mark_import_id', $this->importId)->where('chunk_index', $this->chunkIndex)->first();

        if ($import === null || $chunk === null || $chunk->status === 'completed') {
            return; // duplicate delivery of an already-applied chunk
        }

        $columns = $import->column_map;
        $path = Storage::disk(config('exams.imports.disk'))->path($import->file_path);
        $rows = MarkCsv::readSlice($path, $chunk->byte_offset, $chunk->record_count, $chunk->first_row_number, $chunk->skip_rows ?? []);

        $rows = iterator_to_array($rows, preserve_keys: true);
        $result = (new MarkRowValidator(ExaminationCatalog::for($import->examination_id)))->validate($rows, $columns);

        DB::transaction(function () use ($import, $chunk, $rows, $result) {
            $valid = $result['valid'];
            $errors = $result['errors'];

            try {
                ExaminationLifecycle::lockForMarksWrite($import->examination_id);
            } catch (AppException $e) {
                // Defensive: locking is refused while imports run, but never
                // write marks into an examination that stopped accepting them.
                foreach ($valid as $row) {
                    $errors[] = ['row' => $row['row'], 'raw' => MarkCsv::toRawLine($rows[$row['row']]), 'errors' => [$e->errorCode.': '.$e->getMessage()]];
                }
                $valid = [];
            }

            $this->writeMarks($import, $valid);

            DB::table('mark_import_errors')
                ->where('mark_import_id', $import->id)
                ->where('chunk_index', $this->chunkIndex)
                ->delete();

            foreach (array_chunk($errors, 500) as $batch) {
                DB::table('mark_import_errors')->insert(array_map(fn ($e) => [
                    'mark_import_id' => $import->id,
                    'chunk_index' => $this->chunkIndex,
                    'row_number' => $e['row'],
                    'raw' => $e['raw'],
                    'errors' => json_encode($e['errors']),
                ], $batch));
            }

            $chunk->update([
                'status' => 'completed',
                'row_count' => count($rows),
                'success_rows' => count($valid),
                'failed_rows' => count($errors),
                'attempts' => $this->attempts(),
            ]);
        }, attempts: 3);
    }

    /** @param list<array{row: int, enrollment_id: int, component_id: int, marks: ?string, absent: bool}> $valid */
    private function writeMarks(MarkImport $import, array $valid): void
    {
        // Consistent key order keeps concurrent chunks (and interactive edits)
        // acquiring row locks in the same order, which avoids deadlocks.
        usort($valid, fn ($a, $b) => [$a['enrollment_id'], $a['component_id']] <=> [$b['enrollment_id'], $b['component_id']]);

        $now = now();
        foreach (array_chunk($valid, 1000) as $batch) {
            DB::table('marks')->upsert(
                array_map(fn ($r) => [
                    'examination_id' => $import->examination_id,
                    'enrollment_id' => $r['enrollment_id'],
                    'assessment_component_id' => $r['component_id'],
                    'marks_obtained' => $r['marks'],
                    'is_absent' => $r['absent'],
                    'version' => 1,
                    'source' => 'csv',
                    'mark_import_id' => $import->id,
                    'updated_by' => $import->uploaded_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $batch),
                ['enrollment_id', 'assessment_component_id'],
                [
                    'marks_obtained',
                    'is_absent',
                    'source',
                    'mark_import_id',
                    'updated_by',
                    'updated_at',
                    // Bump the optimistic-lock token so an examiner holding a
                    // stale version in the UI gets a 409 instead of overwriting.
                    'version' => DB::raw('marks.version + 1'),
                ],
            );
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Mark import chunk failed permanently', [
            'import' => $this->importId,
            'chunk' => $this->chunkIndex,
            'error' => $e->getMessage(),
        ]);

        MarkImportChunk::where('mark_import_id', $this->importId)
            ->where('chunk_index', $this->chunkIndex)
            ->update(['status' => 'failed', 'attempts' => $this->attempts()]);
    }
}
