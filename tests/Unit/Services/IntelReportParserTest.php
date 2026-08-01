<?php

namespace Tests\Unit\Services;

use App\Services\IntelReportParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntelReportParserTest extends TestCase
{
    #[DataProvider('invalidReportProvider')]
    public function test_values_that_do_not_fit_the_database_schema_are_rejected(string $report, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        app(IntelReportParser::class)->parse($report);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidReportProvider(): array
    {
        $valid = self::validReport();

        return [
            'nation name' => [
                str_replace('Example Nation', str_repeat('N', 256), $valid),
                'The nation name in the intel report is too long.',
            ],
            'decimal precision' => [
                str_replace('$1,000.00', '$1,000,000,000,000.00', $valid),
                'A resource amount in the intel report exceeds the supported range.',
            ],
            'unsigned captured spies' => [
                str_replace('2 of your spies', '4294967296 of your spies', $valid),
                'The captured spy count exceeds the supported range.',
            ],
        ];
    }

    private static function validReport(): string
    {
        return 'Your spies gathered intelligence about Example Nation. Example Nation has $1,000.00, 2 coal, 3 oil, 4 uranium, 5 lead, 6 iron, 7 bauxite, 8 gasoline, 9 munitions, 10 steel, 11 aluminum and 12 food. The operation cost you $50. 2 of your spies were captured.';
    }
}
