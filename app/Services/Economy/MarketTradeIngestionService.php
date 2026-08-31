<?php

namespace App\Services\Economy;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Models\MarketPriceSnapshot;
use App\Models\MarketPriceSnapshotItem;
use App\Models\MarketTrade;
use App\Services\ApiDateNormalizer;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\TradePriceService;
use App\Services\World\WorldWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class MarketTradeIngestionService
{
    private const MINIMUM_TRADES_PER_SIDE = 10;

    private const PAGE_SIZE = 1000;

    private const MAXIMUM_PAGES = 1000;

    private const MAXIMUM_RUNTIME_SECONDS = 600;

    private const MAXIMUM_TRADE_AMOUNT = 1_000_000_000_000;

    private const WINDOW_DAYS = 7;

    public function __construct(
        private readonly TradePriceService $tradePriceService,
        private readonly WorldWriteGuard $worldWriteGuard,
    ) {}

    /**
     * @throws ProfitabilityPricingUnavailable
     */
    public function refresh(): MarketPriceSnapshot
    {
        $this->worldWriteGuard->assertCanWrite(MarketTrade::class);
        $this->worldWriteGuard->assertCanWrite(MarketPriceSnapshot::class);
        $this->worldWriteGuard->assertCanWrite(MarketPriceSnapshotItem::class);

        $windowEndedAt = CarbonImmutable::now('UTC');
        $windowStartedAt = $windowEndedAt->subDays(self::WINDOW_DAYS);
        $snapshot = null;

        try {
            $this->ingestAcceptedTrades($windowStartedAt);

            $fallback = $this->fallbackPrices();
            $items = [];

            foreach (EconomyRules::TRADE_RESOURCES as $resource) {
                $acquisition = $this->sidePrice($resource, 'sell', $windowStartedAt, $windowEndedAt);
                $liquidation = $this->sidePrice($resource, 'buy', $windowStartedAt, $windowEndedAt);

                $acquisitionFallback = $acquisition === null;
                $liquidationFallback = $liquidation === null;

                if ($acquisitionFallback && ! isset($fallback[$resource])) {
                    throw new ProfitabilityPricingUnavailable("No acquisition price is available for {$resource}.");
                }

                if ($liquidationFallback && ! isset($fallback[$resource])) {
                    throw new ProfitabilityPricingUnavailable("No liquidation price is available for {$resource}.");
                }

                $items[] = [
                    'resource' => $resource,
                    'acquisition_price' => round($acquisition['price'] ?? $fallback[$resource], 2),
                    'liquidation_price' => round($liquidation['price'] ?? $fallback[$resource], 2),
                    'acquisition_trade_count' => $acquisition['count'] ?? 0,
                    'liquidation_trade_count' => $liquidation['count'] ?? 0,
                    'acquisition_volume' => $acquisition['volume'] ?? 0,
                    'liquidation_volume' => $liquidation['volume'] ?? 0,
                    'acquisition_fallback' => $acquisitionFallback,
                    'liquidation_fallback' => $liquidationFallback,
                ];
            }

            $snapshot = DB::transaction(function () use ($items, $windowStartedAt, $windowEndedAt): MarketPriceSnapshot {
                $snapshot = MarketPriceSnapshot::query()->create([
                    'basis' => MarketPriceSet::BASIS,
                    'window_started_at' => $windowStartedAt,
                    'window_ended_at' => $windowEndedAt,
                    'calculated_at' => $windowEndedAt,
                ]);

                $snapshot->items()->createMany($items);

                return $snapshot;
            }, 3);

            return $snapshot->load('items');
        } finally {
            try {
                MarketTrade::query()
                    ->where('accepted_at', '<', $windowEndedAt->subDays(8))
                    ->delete();
            } catch (Throwable $exception) {
                Log::warning('Market trade retention cleanup failed', [
                    'market_price_snapshot_id' => $snapshot?->id,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function ingestAcceptedTrades(CarbonImmutable $cutoff): void
    {
        $page = 1;
        $startedAt = microtime(true);
        $seenPageFingerprints = [];

        do {
            if ($page > self::MAXIMUM_PAGES || microtime(true) - $startedAt > self::MAXIMUM_RUNTIME_SECONDS) {
                throw new RuntimeException('Accepted-trade ingestion exceeded its safety limit.');
            }

            $trades = $this->fetchAcceptedTradePage($page);
            $fingerprint = hash('sha256', json_encode(collect($trades)->pluck('id')->values()->all()));

            if (isset($seenPageFingerprints[$fingerprint])) {
                throw new RuntimeException('Accepted-trade pagination repeated a page without progress.');
            }

            $seenPageFingerprints[$fingerprint] = true;
            $rows = [];
            $reachedCutoff = false;

            foreach ($trades as $trade) {
                $row = $this->normalizeTrade((array) $trade);

                if ($row === null) {
                    continue;
                }

                if (CarbonImmutable::parse($row['accepted_at'])->lt($cutoff)) {
                    $reachedCutoff = true;

                    continue;
                }

                $rows[] = $row;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                MarketTrade::query()->upsert(
                    $chunk,
                    ['id'],
                    [
                        'type',
                        'resource',
                        'side',
                        'amount',
                        'price',
                        'sender_id',
                        'receiver_id',
                        'original_trade_id',
                        'posted_at',
                        'accepted_at',
                        'updated_at',
                    ]
                );
            }

            $page++;
        } while (! $reachedCutoff && count($trades) === self::PAGE_SIZE);
    }

    /**
     * @return list<object>
     */
    private function fetchAcceptedTradePage(int $page): array
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField('trades')
            ->addArgument('first', self::PAGE_SIZE)
            ->addArgument('page', $page)
            ->addArgument('accepted', true)
            ->addArgument('type', GraphQLQueryBuilder::literal('GLOBAL'))
            ->addArgument('orderBy', [[
                'column' => GraphQLQueryBuilder::literal('DATE_ACCEPTED'),
                'order' => GraphQLQueryBuilder::literal('DESC'),
            ]])
            ->addNestedField('data', function (GraphQLQueryBuilder $builder): void {
                $builder->addFields([
                    'id',
                    'type',
                    'date',
                    'sender_id',
                    'receiver_id',
                    'offer_resource',
                    'offer_amount',
                    'buy_or_sell',
                    'price',
                    'accepted',
                    'date_accepted',
                    'original_trade_id',
                ]);
            });

        $response = (new QueryService)->sendQuery($query, handlePagination: false);

        return array_values((array) $response);
    }

    /**
     * @param  array<string, mixed>  $trade
     * @return array<string, mixed>|null
     */
    private function normalizeTrade(array $trade): ?array
    {
        $resource = strtolower((string) ($trade['offer_resource'] ?? ''));
        $side = strtolower((string) ($trade['buy_or_sell'] ?? ''));
        $amount = (int) ($trade['offer_amount'] ?? 0);
        $price = (int) ($trade['price'] ?? 0);
        $id = (int) ($trade['id'] ?? 0);
        $acceptedAt = ApiDateNormalizer::normalizeTimestamp($trade['date_accepted'] ?? null);

        if (
            $id <= 0
            || strtoupper((string) ($trade['type'] ?? '')) !== 'GLOBAL'
            || ($trade['accepted'] ?? null) !== true
            || ! in_array($resource, EconomyRules::TRADE_RESOURCES, true)
            || ! in_array($side, ['buy', 'sell'], true)
            || $amount <= 0
            || $amount > self::MAXIMUM_TRADE_AMOUNT
            || $price < 1
            || $price > 1_000_000
            || $acceptedAt === null
            || CarbonImmutable::parse($acceptedAt)->gt(CarbonImmutable::now('UTC')->addMinutes(5))
        ) {
            return null;
        }

        $now = now()->toDateTimeString();

        return [
            'id' => $id,
            'type' => 'GLOBAL',
            'resource' => $resource,
            'side' => $side,
            'amount' => $amount,
            'price' => $price,
            'sender_id' => isset($trade['sender_id']) ? (int) $trade['sender_id'] : null,
            'receiver_id' => isset($trade['receiver_id']) ? (int) $trade['receiver_id'] : null,
            'original_trade_id' => isset($trade['original_trade_id']) ? (int) $trade['original_trade_id'] : null,
            'posted_at' => ApiDateNormalizer::normalizeTimestamp($trade['date'] ?? null),
            'accepted_at' => $acceptedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array{price: float, count: int, volume: int}|null
     */
    private function sidePrice(
        string $resource,
        string $side,
        CarbonImmutable $windowStartedAt,
        CarbonImmutable $windowEndedAt
    ): ?array {
        $query = MarketTrade::query()
            ->where('resource', $resource)
            ->where('side', $side)
            ->whereBetween('accepted_at', [$windowStartedAt, $windowEndedAt]);
        $statistics = (clone $query)
            ->selectRaw('COUNT(*) as trade_count, COALESCE(SUM(amount), 0) as volume')
            ->first();
        $tradeCount = (int) ($statistics?->trade_count ?? 0);
        $volume = (int) ($statistics?->volume ?? 0);

        if ($tradeCount < self::MINIMUM_TRADES_PER_SIDE || $volume <= 0) {
            return null;
        }

        $halfVolume = $volume / 2;
        $cumulativeVolume = 0;
        $weightedMedian = 0.0;

        $orderedTrades = (clone $query)
            ->select(['id', 'price', 'amount'])
            ->orderBy('price')
            ->orderBy('id')
            ->lazy(1000);

        foreach ($orderedTrades as $trade) {
            $cumulativeVolume += (int) $trade->amount;
            $weightedMedian = (float) $trade->price;

            if ($cumulativeVolume >= $halfVolume) {
                break;
            }
        }

        return [
            'price' => $weightedMedian,
            'count' => $tradeCount,
            'volume' => $volume,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function fallbackPrices(): array
    {
        $average = $this->tradePriceService->get24hAverage();
        $prices = [];

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $price = (float) ($average->{$resource} ?? 0.0);

            if ($price >= 1 && $price <= 1_000_000) {
                $prices[$resource] = $price;
            }
        }

        return $prices;
    }
}
