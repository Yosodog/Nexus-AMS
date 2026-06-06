<?php

namespace Tests\Unit;

use App\Support\CsvExport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class CsvExportTest extends UnitTestCase
{
    #[DataProvider('formulaCellProvider')]
    public function test_sanitize_cell_prefixes_formula_like_strings(string $value): void
    {
        $this->assertSame("'{$value}", CsvExport::sanitizeCell($value));
    }

    public function test_sanitize_cell_leaves_numbers_and_safe_strings_unchanged(): void
    {
        $this->assertSame(-10, CsvExport::sanitizeCell(-10));
        $this->assertSame('Regular text', CsvExport::sanitizeCell('Regular text'));
        $this->assertSame("'=already escaped", CsvExport::sanitizeCell("'=already escaped"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formulaCellProvider(): array
    {
        return [
            'equals' => ['=HYPERLINK("https://example.test")'],
            'plus' => ['+SUM(1,1)'],
            'minus' => ['-SUM(1,1)'],
            'at' => ['@SUM(1,1)'],
            'tab' => ["\t=SUM(1,1)"],
            'carriage return' => ["\r=SUM(1,1)"],
            'newline' => ["\n=SUM(1,1)"],
            'leading spaces' => ['   =SUM(1,1)'],
        ];
    }
}
