<?php

namespace App\Jobs;

use App\Models\MarkImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Derives the import's final status from its chunk rows. Pure recomputation,
 * so running it twice (duplicate delivery, retries) gives the same answer.
 */
class FinalizeMarkImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $importId)
    {
        $this->onQueue(config('exams.imports.queue'));
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $import = MarkImport::whereKey($this->importId)->lockForUpdate()->first();
            if ($import === null || $import->status !== MarkImport::PROCESSING) {
                return;
            }

            $stats = DB::table('mark_import_chunks')
                ->where('mark_import_id', $import->id)
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN row_count ELSE 0 END), 0) AS processed,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN success_rows ELSE 0 END), 0) AS success,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN failed_rows ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN status <> 'completed' THEN 1 ELSE 0 END), 0) AS unfinished_chunks
                ")
                ->first();

            $duplicates = DB::table('mark_import_errors')
                ->where('mark_import_id', $import->id)
                ->where('chunk_index', -1)
                ->count();

            $failedRows = (int) $stats->failed + $duplicates;
            $unfinished = (int) $stats->unfinished_chunks;

            $status = match (true) {
                $unfinished > 0 => MarkImport::FAILED,
                $failedRows > 0 => MarkImport::COMPLETED_WITH_ERRORS,
                default => MarkImport::COMPLETED,
            };

            $import->update([
                'status' => $status,
                'processed_rows' => (int) $stats->processed + $duplicates,
                'success_rows' => (int) $stats->success,
                'failed_rows' => $failedRows,
                'failure_reason' => $unfinished > 0
                    ? "{$unfinished} chunk(s) failed after retries; their rows were not applied. POST /retry to re-run only those chunks."
                    : null,
                'finished_at' => now(),
            ]);
        });
    }
}
