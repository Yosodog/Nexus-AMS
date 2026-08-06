<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\AllianceFinanceData;
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

    public function test_occurrence_timestamp_survives_queued_payload_round_trip(): void
    {
        $occurredAt = Carbon::parse('2026-08-02T05:06:07+00:00');
        $payload = (new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_INCOME,
            category: 'mmr_income',
            description: 'Historical MMR contribution',
            date: $occurredAt,
            money: 125,
            occurredAt: $occurredAt,
        ))->toArray();

        $entry = app(AllianceFinanceService::class)->recordIncome(
            AllianceFinanceData::fromArray($payload)
        );

        $this->assertSame('2026-08-02', $entry->date->toDateString());
        $this->assertSame($occurredAt->toAtomString(), $entry->created_at->toAtomString());
        $this->assertSame($occurredAt->toAtomString(), $entry->updated_at->toAtomString());
    }

    public function test_replaying_a_sourced_entry_does_not_duplicate_it(): void
    {
        $data = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_INCOME,
            category: 'tax',
            description: 'Imported tax record',
            date: Carbon::parse('2026-08-02'),
            money: 125,
            sourceType: 'tax_record',
            sourceId: 456,
        );

        $service = app(AllianceFinanceService::class);
        $first = $service->recordIncome($data);
        $second = $service->recordIncome($data);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('alliance_finance_entries', 1);
    }
}
