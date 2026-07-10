<?php

namespace Tests\Feature\Finance;

use App\Models\AllianceFinanceEntry;
use App\Services\Finance\AllianceFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\FeatureTestCase;

class AllianceFinanceServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_date_range_includes_entries_on_both_boundaries(): void
    {
        AllianceFinanceEntry::query()->create([
            'date' => '2026-07-01',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Opening boundary',
            'money' => 125,
        ]);

        AllianceFinanceEntry::query()->create([
            'date' => '2026-07-15',
            'direction' => AllianceFinanceEntry::DIRECTION_EXPENSE,
            'category' => 'grant',
            'description' => 'Closing boundary',
            'money' => 25,
        ]);

        AllianceFinanceEntry::query()->create([
            'date' => '2026-07-16',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Outside range',
            'money' => 999,
        ]);

        $totals = app(AllianceFinanceService::class)->getTotals(
            Carbon::parse('2026-07-01')->startOfDay(),
            Carbon::parse('2026-07-15')->endOfDay(),
        );

        $this->assertSame(125.0, $totals['income']);
        $this->assertSame(25.0, $totals['expense']);
        $this->assertSame(100.0, $totals['net']);
    }
}
