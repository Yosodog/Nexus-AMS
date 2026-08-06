<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\AllianceFinanceData;
use App\Models\AllianceFinanceEntry;
use App\Services\Finance\AllianceFinanceService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    public function test_aggregate_caches_store_plain_arrays_and_rehydrate_rows(): void
    {
        $this->useLaravel13SerializedArrayCache();

        AllianceFinanceEntry::query()->create([
            'date' => '2026-08-01',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Cached tax income',
            'money' => 125,
        ]);

        $from = Carbon::parse('2026-08-01')->startOfDay();
        $to = Carbon::parse('2026-08-01')->endOfDay();
        $service = app(AllianceFinanceService::class);

        $dailySummary = $service->getDailySummary($from, $to);
        $categoryBreakdown = $service->getDailyCategoryBreakdown($from, $to);

        $this->assertContainsOnlyInstancesOf(AllianceFinanceEntry::class, $dailySummary);
        $this->assertContainsOnlyInstancesOf(AllianceFinanceEntry::class, $categoryBreakdown);
        $this->assertArrayRowPayload(Cache::get($this->financeCacheKey('daily_summary', $from, $to)));
        $this->assertArrayRowPayload(Cache::get($this->financeCacheKey('daily_category_breakdown', $from, $to)));

        AllianceFinanceEntry::query()->delete();

        $this->assertCount(1, $service->getDailySummary($from, $to));
        $this->assertCount(1, $service->getDailyCategoryBreakdown($from, $to));
    }

    public function test_daily_summary_recovers_from_a_legacy_poisoned_collection(): void
    {
        $this->useLaravel13SerializedArrayCache();

        AllianceFinanceEntry::query()->create([
            'date' => '2026-08-01',
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Legacy cached tax income',
            'money' => 125,
        ]);

        $from = Carbon::parse('2026-08-01')->startOfDay();
        $to = Carbon::parse('2026-08-01')->endOfDay();
        $cacheKey = $this->financeCacheKey('daily_summary', $from, $to);

        Cache::put($cacheKey, AllianceFinanceEntry::query()->get(), now()->addMinutes(10));

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get($cacheKey));

        $summary = app(AllianceFinanceService::class)->getDailySummary($from, $to);

        $this->assertCount(1, $summary);
        $this->assertContainsOnlyInstancesOf(AllianceFinanceEntry::class, $summary);
        $this->assertSame(125.0, (float) $summary->first()->money);
        $this->assertArrayRowPayload(Cache::get($cacheKey));
    }

    private function useLaravel13SerializedArrayCache(): void
    {
        Cache::swap(new Repository(new ArrayStore(true, config('cache.serializable_classes'))));
    }

    private function financeCacheKey(string $prefix, Carbon $from, Carbon $to): string
    {
        return sprintf(
            'finance:%s:%s:%s:%s',
            $prefix,
            $from->toDateString(),
            $to->toDateString(),
            md5(json_encode([]))
        );
    }

    private function assertArrayRowPayload(mixed $payload): void
    {
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload);

        foreach ($payload as $row) {
            $this->assertIsArray($row);
        }
    }
}
