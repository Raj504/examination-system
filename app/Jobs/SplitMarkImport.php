<?php

namespace App\Jobs;

use App\Exceptions\AppException;
use App\Models\MarkImport;
use App\Services\Imports\MarkCsv;
use App\Services\Imports\MarkImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pass 1 of an import: a single sequential scan of the file that
 *  - validates the header,
 *  - records the byte offset of every chunk (so chunk jobs can seek directly
 *    to their slice - job payloads stay tiny and nothing is buffered in Redis),
 *  - rejects duplicate (student, course, component) keys within the file, so
 *    the outcome never depends on which parallel chunk happens to commit last.
 *
 * Re-running it is safe: it wipes its own previous output first.
 */
class SplitMarkImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $importId)
    {
        $this->onQueue(config('exams.imports.queue'));
    }

    public function uniqueId(): string
    {
        return $this->importId;
    }

    public function handle(MarkImportService $service): void
    {
        $import = MarkImport::find($this->importId);

        if ($import === null || ! in_array($import->status, [MarkImport::PENDING, MarkImport::SPLITTING], true)) {
            return; // already split (duplicate delivery) or cancelled
        }

        $import->update(['status' => MarkImport::SPLITTING, 'started_at' => $import->started_at ?? now()]);

        $path = Storage::disk(config('exams.imports.disk'))->path($import->file_path);
        $handle = MarkCsv::open($path);

        try {
            try {
                $columns = MarkCsv::readHeader($handle);
            } catch (AppException $e) {
                $this->failImport($import, $e->getMessage());

                return;
            }

            DB::transaction(function () use ($import) {
                $import->chunks()->delete();
                $import->errors()->delete();
            });

            $plan = $this->plan($import, $handle, $columns);
        } finally {
            fclose($handle);
        }

        if ($plan === null) {
            return;
        }

        $import->update([
            'status' => MarkImport::PROCESSING,
            'column_map' => $columns,
            'total_rows' => $plan['total_rows'],
            'total_chunks' => $plan['total_chunks'],
        ]);

        $service->dispatchChunks($import);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, int>  $columns
     * @return array{total_rows: int, total_chunks: int}|null
     */
    private function plan(MarkImport $import, $handle, array $columns): ?array
    {
        $chunkSize = max(1, (int) config('exams.imports.chunk_size'));
        $maxRows = (int) config('exams.imports.max_rows');

        $seen = [];          // xxh128(natural key) => first row number
        $chunks = [];
        $errors = [];
        $totalRows = 0;
        $rowNumber = 1;      // header is row 1
        $chunkIndex = 0;

        $current = null;
        $startChunk = function (int $offset, int $firstRow) use (&$current, &$chunkIndex) {
            $current = ['chunk_index' => $chunkIndex++, 'byte_offset' => $offset, 'first_row_number' => $firstRow, 'record_count' => 0, 'data_rows' => 0, 'skip_rows' => []];
        };

        while (true) {
            $offset = ftell($handle);
            $record = MarkCsv::readRecord($handle);
            if ($record === false) {
                break;
            }
            $rowNumber++;

            if ($current === null) {
                $startChunk($offset, $rowNumber);
            }
            $current['record_count']++;

            if (MarkCsv::isBlank($record)) {
                continue;
            }

            $totalRows++;
            $current['data_rows']++;

            if ($totalRows > $maxRows) {
                $this->failImport($import, "File exceeds the maximum of {$maxRows} rows.");

                return null;
            }

            if (count($record) >= count(MarkCsv::REQUIRED_COLUMNS)) {
                $key = hash('xxh128', MarkCsv::naturalKey($record, $columns), true);
                if (isset($seen[$key])) {
                    $current['skip_rows'][] = $rowNumber;
                    $errors[] = [
                        'mark_import_id' => $import->id,
                        'chunk_index' => -1,
                        'row_number' => $rowNumber,
                        'raw' => MarkCsv::toRawLine($record),
                        'errors' => json_encode(["duplicate_row: same student/course/component as row {$seen[$key]}"]),
                    ];
                } else {
                    $seen[$key] = $rowNumber;
                }
            }

            if ($current['data_rows'] >= $chunkSize) {
                $chunks[] = $current;
                $current = null;
            }

            if (count($chunks) >= 500) {
                $this->flush($import, $chunks, $errors);
            }
            if (count($errors) >= 1000) {
                $this->flush($import, [], $errors);
            }
        }

        if ($current !== null && $current['record_count'] > 0) {
            $chunks[] = $current;
        }
        $this->flush($import, $chunks, $errors);

        return ['total_rows' => $totalRows, 'total_chunks' => $chunkIndex];
    }

    private function flush(MarkImport $import, array &$chunks, array &$errors): void
    {
        $now = now();

        if ($chunks !== []) {
            DB::table('mark_import_chunks')->insert(array_map(fn ($c) => [
                'mark_import_id' => $import->id,
                'chunk_index' => $c['chunk_index'],
                'status' => 'pending',
                'byte_offset' => $c['byte_offset'],
                'record_count' => $c['record_count'],
                'first_row_number' => $c['first_row_number'],
                'skip_rows' => $c['skip_rows'] === [] ? null : json_encode($c['skip_rows']),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunks));
            $chunks = [];
        }

        if ($errors !== []) {
            DB::table('mark_import_errors')->insert($errors);
            $errors = [];
        }
    }

    private function failImport(MarkImport $import, string $reason): void
    {
        $import->update([
            'status' => MarkImport::FAILED,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Mark import split failed', ['import' => $this->importId, 'error' => $e->getMessage()]);

        MarkImport::whereKey($this->importId)->update([
            'status' => MarkImport::FAILED,
            'failure_reason' => 'Splitting failed: '.$e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
