<?php

namespace App\Http\Resources;

use App\Models\MarkImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin MarkImport */
class MarkImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // While running, progress is derived live from the chunk table (the
        // import row itself is only written by the idempotent finaliser).
        $progress = null;
        if ($this->status === MarkImport::PROCESSING) {
            $done = DB::table('mark_import_chunks')
                ->where('mark_import_id', $this->id)
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) AS chunks, COALESCE(SUM(row_count), 0) AS rows_done')
                ->first();

            $progress = [
                'chunks_completed' => (int) $done->chunks,
                'chunks_total' => $this->total_chunks,
                'rows_processed' => (int) $done->rows_done,
                'percent' => $this->total_chunks > 0 ? round($done->chunks / $this->total_chunks * 100, 1) : 0,
            ];
        }

        return [
            'id' => $this->id,
            'examination_id' => $this->examination_id,
            'original_filename' => $this->original_filename,
            'file_hash' => $this->file_hash,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'total_chunks' => $this->total_chunks,
            'progress' => $progress,
            'failure_reason' => $this->failure_reason,
            'uploaded_by' => $this->uploaded_by,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'links' => [
                'self' => url("/api/v1/mark-imports/{$this->id}"),
                'errors' => url("/api/v1/mark-imports/{$this->id}/errors"),
                'errors_csv' => url("/api/v1/mark-imports/{$this->id}/errors.csv"),
            ],
        ];
    }
}
