<?php

namespace App\Services\Imports;

use App\Exceptions\AppException;
use App\Jobs\FinalizeMarkImport;
use App\Jobs\ProcessMarkImportChunk;
use App\Jobs\SplitMarkImport;
use App\Models\Examination;
use App\Models\MarkImport;
use App\Services\ExaminationLifecycle;
use Illuminate\Bus\Batch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MarkImportService
{
    /**
     * Accepts an upload: stores the file, records the import and queues the
     * splitter. The HTTP request never parses the CSV body, so response time
     * is independent of file size.
     *
     * Uploading a byte-identical file for the same examination returns the
     * existing import instead of processing it twice.
     *
     * @return array{import: MarkImport, created: bool}
     */
    public function create(Examination $exam, UploadedFile $file, string $actor): array
    {
        $hash = hash_file('sha256', $file->getRealPath());

        if ($existing = $this->findByHash($exam->id, $hash)) {
            return ['import' => $existing, 'created' => false];
        }

        $id = (string) Str::uuid7();
        $disk = config('exams.imports.disk');
        $path = $file->storeAs("imports/{$exam->id}", "{$id}.csv", $disk);

        try {
            $import = DB::transaction(function () use ($exam, $file, $hash, $id, $path, $actor) {
                // Shared lock: an upload cannot slip in while marks are being locked.
                ExaminationLifecycle::lockForMarksWrite($exam->id);

                $import = MarkImport::create([
                    'id' => $id,
                    'examination_id' => $exam->id,
                    'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'file_path' => $path,
                    'file_hash' => $hash,
                    'status' => MarkImport::PENDING,
                    'uploaded_by' => $actor,
                ]);

                SplitMarkImport::dispatch($import->id)->afterCommit();

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with an identical concurrent upload.
            Storage::disk($disk)->delete($path);

            return ['import' => $this->findByHash($exam->id, $hash), 'created' => false];
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        return ['import' => $import->refresh(), 'created' => true];
    }

    /**
     * Queues one job per chunk that has not completed yet, then a finaliser.
     * Used both for the first run and for operator-triggered retries.
     */
    public function dispatchChunks(MarkImport $import): void
    {
        $chunkIndexes = $import->chunks()
            ->where('status', '!=', 'completed')
            ->orderBy('chunk_index')
            ->pluck('chunk_index');

        if ($chunkIndexes->isEmpty()) {
            FinalizeMarkImport::dispatch($import->id);

            return;
        }

        $importId = $import->id;
        $jobs = $chunkIndexes->map(fn (int $index) => new ProcessMarkImportChunk($importId, $index))->all();

        $batch = Bus::batch($jobs)
            ->name("mark-import:{$importId}")
            ->onQueue(config('exams.imports.queue'))
            // One poison chunk must not cancel the other 99; the finaliser
            // reports failed chunks and they can be retried individually.
            ->allowFailures()
            ->finally(function (Batch $batch) use ($importId) {
                FinalizeMarkImport::dispatch($importId);
            })
            ->dispatch();

        MarkImport::whereKey($importId)->update(['batch_id' => $batch->id]);
    }

    /** Re-queues the chunks of a failed import that did not complete. */
    public function retry(MarkImport $import): MarkImport
    {
        $import = DB::transaction(function () use ($import) {
            $locked = MarkImport::whereKey($import->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MarkImport::FAILED) {
                throw AppException::conflict("Only failed imports can be retried (status is {$locked->status}).", 'import_not_retryable');
            }

            if ($locked->total_chunks === 0 || $locked->column_map === null) {
                throw AppException::conflict('Import failed before it was split; upload a corrected file instead.', 'import_not_retryable');
            }

            ExaminationLifecycle::lockForMarksWrite($locked->examination_id);

            $locked->update(['status' => MarkImport::PROCESSING, 'failure_reason' => null, 'finished_at' => null]);

            return $locked;
        });

        $this->dispatchChunks($import);

        return $import->refresh();
    }

    private function findByHash(int $examinationId, string $hash): ?MarkImport
    {
        return MarkImport::where('examination_id', $examinationId)->where('file_hash', $hash)->first();
    }
}
