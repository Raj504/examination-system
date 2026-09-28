<?php

namespace App\Services;

/**
 * Converts marks between text and whole numbers.
 *
 * Why whole numbers? Computers store decimals like 0.1 inexactly, so
 * comparisons such as "45.5 > 45.49" can go wrong. We therefore keep marks
 * as "hundredths": 45.5 becomes 4550 and 30 becomes 3000. All checks
 * (e.g. "is this above the maximum?") are then simple integer comparisons.
 */
class MarkParser
{
    /** Words that mean "the student was absent". */
    private const ABSENT_WORDS = ['AB', 'ABS', 'ABSENT'];

    public static function isAbsent(string $text): bool
    {
        return in_array(strtoupper(trim($text)), self::ABSENT_WORDS, true);
    }

    /**
     * "45.5" -> 4550. Returns null if the text is not a valid mark
     * (a non-negative number with at most 2 decimal places).
     */
    public static function toHundredths(string|int|float $text): ?int
    {
        $text = trim((string) $text);

        if (! preg_match('/^(\d{1,4})(?:\.(\d{1,2}))?$/', $text, $parts)) {
            return null;
        }

        $whole = (int) $parts[1];
        $decimals = (int) str_pad($parts[2] ?? '', 2, '0'); // ".5" -> 50, ".05" -> 5

        return $whole * 100 + $decimals;
    }

    /** 4550 -> "45.50" (the format stored in the database). */
    public static function format(int $hundredths): string
    {
        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Database value "45.50" -> 4550 (null stays null). */
    public static function fromDatabase(string|int|float|null $value): ?int
    {
        return $value === null ? null : (int) round(((float) $value) * 100);
    }
}
