<?php

namespace App\Services\Imports;

use App\Exceptions\AppException;
use Generator;
use RuntimeException;

/**
 * Streaming CSV access shared by the splitter and the chunk workers.
 *
 * Both sides iterate records with the same fgetcsv() loop so that the byte
 * offsets recorded by the splitter line up exactly with what a chunk worker
 * reads, including quoted fields that span multiple lines.
 */
final class MarkCsv
{
    public const REQUIRED_COLUMNS = ['registration_no', 'course_code', 'component_code', 'marks'];

    /** @return resource */
    public static function open(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open import file {$path}.");
        }

        return $handle;
    }

    /**
     * Reads and validates the header row.
     *
     * @param  resource  $handle
     * @return array<string, int> column name => index
     */
    public static function readHeader($handle): array
    {
        $header = fgetcsv($handle, escape: '\\');
        if ($header === false || $header === [null]) {
            throw AppException::unprocessable('CSV file is empty.', 'empty_file');
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // strip UTF-8 BOM

        $map = [];
        foreach ($header as $index => $name) {
            $map[strtolower(trim((string) $name))] = $index;
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($map)));
        if ($missing !== []) {
            throw AppException::unprocessable(
                'CSV header is missing required columns: '.implode(', ', $missing).'.',
                'invalid_header',
                ['required' => self::REQUIRED_COLUMNS],
            );
        }

        return array_intersect_key($map, array_flip(self::REQUIRED_COLUMNS));
    }

    /** @param  resource  $handle */
    public static function readRecord($handle): array|false
    {
        return fgetcsv($handle, escape: '\\');
    }

    public static function isBlank(array $record): bool
    {
        return $record === [null] || (count($record) === 1 && trim((string) $record[0]) === '');
    }

    /**
     * Yields the data rows of one chunk.
     *
     * @param  list<int>  $skipRows
     * @return Generator<int, array> row number => raw record
     */
    public static function readSlice(string $path, int $byteOffset, int $recordCount, int $firstRowNumber, array $skipRows = []): Generator
    {
        $handle = self::open($path);
        $skip = array_flip($skipRows);

        try {
            fseek($handle, $byteOffset);
            $rowNumber = $firstRowNumber;

            for ($i = 0; $i < $recordCount; $i++, $rowNumber++) {
                $record = self::readRecord($handle);
                if ($record === false) {
                    break;
                }
                if (self::isBlank($record) || isset($skip[$rowNumber])) {
                    continue;
                }

                yield $rowNumber => $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param  array<string, int>  $columns */
    public static function field(array $record, array $columns, string $name): string
    {
        return trim((string) ($record[$columns[$name]] ?? ''));
    }

    /** Case-insensitive natural key used for duplicate detection. */
    public static function naturalKey(array $record, array $columns): string
    {
        return strtoupper(self::field($record, $columns, 'registration_no'))
            .'|'.strtoupper(self::field($record, $columns, 'course_code'))
            .'|'.strtoupper(self::field($record, $columns, 'component_code'));
    }

    public static function toRawLine(array $record): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $record, escape: '\\');
        rewind($stream);
        $line = rtrim((string) stream_get_contents($stream), "\r\n");
        fclose($stream);

        return mb_substr($line, 0, 1000);
    }
}
