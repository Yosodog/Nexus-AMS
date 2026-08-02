<?php

namespace Tests\Feature\Services\Economy;

use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Models\MarketTrade;
use App\Models\TradePrice;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketTradeIngestionService;
use App\Services\Economy\MarketValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_ingests_recent_fills_and_publishes_side_specific_weighted_medians(): void
    {
        $this->seedAggregatePrices(777);
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'trades' => [
                        'data' => $this->tradePayloads(),
                    ],
                ],
            ]),
        ]);

        $snapshot = app(MarketTradeIngestionService::class)->refresh();
        $items = $snapshot->items->keyBy('resource');

        $this->assertSame(219, MarketTrade::query()->count());
        $this->assertSame(104.0, $items['oil']->acquisition_price);
        $this->assertSame(94.0, $items['oil']->liquidation_price);
        $this->assertFalse($items['oil']->acquisition_fallback);
        $this->assertSame(777.0, $items['coal']->acquisition_price);
        $this->assertTrue($items['coal']->acquisition_fallback);

        $priceSet = app(MarketValuationService::class)->current();
        $this->assertSame($snapshot->id, $priceSet->snapshotId);
        $this->assertContains('coal', $priceSet->fallbackResources);
        $this->assertFalse($priceSet->stale);

        $secondSnapshot = app(MarketTradeIngestionService::class)->refresh();
        $this->assertNotSame($snapshot->id, $secondSnapshot->id);
        $this->assertSame(219, MarketTrade::query()->count());

        $secondSnapshot->forceFill(['calculated_at' => now()->addHour()])->save();
        $this->assertSame($snapshot->id, app(MarketValuationService::class)->current()->snapshotId);
        $secondSnapshot->forceFill(['calculated_at' => now()])->save();

        CarbonImmutable::setTestNow('2026-08-02 01:00:00 UTC');
        $this->assertTrue(app(MarketValuationService::class)->current()->stale);

        CarbonImmutable::setTestNow('2026-08-04 19:00:00 UTC');
        $aggregateFallback = app(MarketValuationService::class)->current();
        $this->assertNull($aggregateFallback->snapshotId);
        $this->assertSame(EconomyRules::TRADE_RESOURCES, $aggregateFallback->fallbackResources);

        Http::assertSent(function ($request): bool {
            $query = (string) data_get($request->data(), 'query');

            return str_contains($query, 'DATE_ACCEPTED')
                && str_contains($query, 'type: GLOBAL')
                && ! str_contains($query, 'after:');
        });
    }

    #[Test]
    public function it_pages_by_acceptance_time_and_includes_the_seven_day_boundary(): void
    {
        $this->seedAggregatePrices(777);
        $firstPage = [];

        for ($id = 1; $id <= 1000; $id++) {
            $firstPage[] = $this->tradePayload(
                id: $id,
                acceptedAt: '2026-08-01T17:00:00Z'
            );
        }

        $boundaryTrade = $this->tradePayload(
            id: 1001,
            acceptedAt: '2026-07-25T18:00:00Z'
        );
        $oldTrade = $this->tradePayload(
            id: 1002,
            acceptedAt: '2026-07-25T17:59:59Z'
        );
        Http::fakeSequence()
            ->push(['data' => ['trades' => ['data' => $firstPage]]])
            ->push(['data' => ['trades' => ['data' => [$boundaryTrade, $oldTrade]]]]);

        app(MarketTradeIngestionService::class)->refresh();

        $this->assertSame(1001, MarketTrade::query()->count());
        $this->assertDatabaseHas('market_trades', ['id' => 1001]);
        $this->assertDatabaseMissing('market_trades', ['id' => 1002]);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_throws_when_neither_a_snapshot_nor_complete_aggregate_prices_exist(): void
    {
        $this->expectException(ProfitabilityPricingUnavailable::class);

        app(MarketValuationService::class)->current();
    }

    #[Test]
    public function retention_runs_even_when_price_snapshot_publication_fails(): void
    {
        MarketTrade::query()->create([
            'id' => 9999,
            'type' => 'GLOBAL',
            'resource' => 'coal',
            'side' => 'sell',
            'amount' => 1,
            'price' => 100,
            'accepted_at' => now()->subDays(9),
        ]);
        Http::fake([
            '*' => Http::response(['data' => ['trades' => ['data' => []]]]),
        ]);

        $this->expectException(ProfitabilityPricingUnavailable::class);

        try {
            app(MarketTradeIngestionService::class)->refresh();
        } finally {
            $this->assertDatabaseMissing('market_trades', ['id' => 9999]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tradePayloads(): array
    {
        $id = 1;
        $trades = [];

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            foreach (['sell' => 100, 'buy' => 90] as $side => $basePrice) {
                $count = $resource === 'coal' && $side === 'sell' ? 9 : 10;

                for ($offset = 0; $offset < $count; $offset++) {
                    $trades[] = [
                        'id' => $id++,
                        'type' => 'GLOBAL',
                        'date' => '2026-07-01T00:00:00Z',
                        'sender_id' => 1,
                        'receiver_id' => 2,
                        'offer_resource' => $resource,
                        'offer_amount' => 1,
                        'buy_or_sell' => $side,
                        'price' => $basePrice + $offset,
                        'accepted' => true,
                        'date_accepted' => '2026-08-01T17:00:00Z',
                        'original_trade_id' => 1000 + $id,
                    ];
                }
            }
        }

        return $trades;
    }

    /**
     * @return array<string, mixed>
     */
    private function tradePayload(int $id, string $acceptedAt): array
    {
        return [
            'id' => $id,
            'type' => 'GLOBAL',
            'date' => '2026-07-01T00:00:00Z',
            'sender_id' => 1,
            'receiver_id' => 2,
            'offer_resource' => 'coal',
            'offer_amount' => 1,
            'buy_or_sell' => 'sell',
            'price' => 100,
            'accepted' => true,
            'date_accepted' => $acceptedAt,
            'original_trade_id' => 5000 + $id,
        ];
    }

    private function seedAggregatePrices(int $price): void
    {
        TradePrice::query()->create([
            'date' => '2026-08-01',
            ...array_fill_keys(EconomyRules::TRADE_RESOURCES, $price),
            'credits' => $price,
        ]);
    }
}
