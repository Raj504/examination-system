<?php

namespace Tests\Unit;

use App\Services\MarkParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkParserTest extends TestCase
{
    public static function examples(): array
    {
        return [
            ['45', 4500],
            ['45.5', 4550],
            ['45.05', 4505],
            [' 0 ', 0],
            ['0.1', 10],
            ['100.00', 10000],
            ['-1', null],     // negative
            ['45.555', null], // more than 2 decimals
            ['abc', null],
            ['', null],
            ['1e2', null],
            ['.5', null],
        ];
    }

    #[DataProvider('examples')]
    public function test_to_hundredths(string $text, ?int $expected): void
    {
        $this->assertSame($expected, MarkParser::toHundredths($text));
    }

    public function test_format_and_database_conversion(): void
    {
        $this->assertSame('45.05', MarkParser::format(4505));
        $this->assertSame('0.00', MarkParser::format(0));
        $this->assertSame(4505, MarkParser::fromDatabase('45.05'));
        $this->assertNull(MarkParser::fromDatabase(null));
    }

    public function test_absent_words(): void
    {
        $this->assertTrue(MarkParser::isAbsent('AB'));
        $this->assertTrue(MarkParser::isAbsent(' absent '));
        $this->assertFalse(MarkParser::isAbsent('0'));
    }
}
