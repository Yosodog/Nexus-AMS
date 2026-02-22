<?php

namespace App\Services\Finance;

use App\DataTransferObjects\AllianceFinanceData;
use App\Models\AllianceFinanceEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AllianceFinanceService
{
    private const CACHE_TTL_MINUTES = 10;

    public function __construct(
        private readonly FinanceCategoryRegistry $categories,
    ) {}

    /**
     * Persist a single income entry.
     */
    public function recordIncome(AllianceFinanceData $data): AllianceFinanceEntry
    {
        return $this->persist($data, AllianceFinanceEntry::DIRECTION_INCOME);
    }

    /**
     * Persist a single expense entry.
     */
    public function recordExpense(AllianceFinanceData $data): AllianceFinanceEntry
    {
        return $this->persist($data, AllianceFinanceEntry::DIRECTION_EXPENSE);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getDailySummary(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        $cacheKey = $this->cacheKey('daily_summary', $from, $to, $filters);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($from, $to, $filters) {
                return $this->rangeQuery($from, $to, $filters)
                    ->selectRaw(
                        'date, direction, SUM(`money`) as `money`, SUM(`coal`) as `coal`, SUM(`oil`) as `oil`, '.
                        'SUM(`uranium`) as `uranium`, SUM(`iron`) as `iron`, SUM(`bauxite`) as `bauxite`, SUM(`lead`) as `lead`, '.
                        'SUM(`gasoline`) as `gasoline`, SUM(`munitions`) as `munitions`, SUM(`steel`) as `steel`, SUM(`aluminum`) as `aluminum`, '.
                        'SUM(`food`) as `food`'
                    )
                    ->groupBy('date', 'direction')
                    ->orderBy('date')
                    ->get();
            }
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getDailyCategoryBreakdown(
        CarbonInterface $from,
        CarbonInterface $to,
        array $filters = []
    ): Collection {
        $cacheKey = $this->cacheKey('daily_category_breakdown', $from, $to, $filters);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($from, $to, $filters) {
                return $this->rangeQuery($from, $to, $filters)
                    ->selectRaw('date, category, SUM(`money`) as `money`')
                    ->groupBy('date', 'category')
                    ->orderBy('date')
                    ->get();
            }
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{income: float, expense: float, net: float}
     */
    public function getTotals(CarbonInterface $from, CarbonInterface $to, array $filters = []): array
    {
        $cacheKey = $this->cacheKey('totals', $from, $to, $filters);

        /** @var array{income: float, expense: float, net: float} $totals */
        $totals = Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($from, $to, $filters) {
                $sums = $this->rangeQuery($from, $to, $filters)
                    ->selectRaw('direction, SUM(`money`) as `money`')
                    ->groupBy('direction')
                    ->pluck('money', 'direction');

                $income = (float) ($sums[AllianceFinanceEntry::DIRECTION_INCOME] ?? 0.0);
                $expense = (float) ($sums[AllianceFinanceEntry::DIRECTION_EXPENSE] ?? 0.0);

                return [
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                ];
            }
        );

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getEntries(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        $entries = $this->rangeQuery($from, $to, $filters)
            ->with([
                'nation:id,nation_name,leader_name',
                'account:id,name',
            ])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $entries
            ->filter(fn (AllianceFinanceEntry $entry) => $entry->sourceClass() !== null)
            ->load('source');

        return $entries;
    }

    private function persist(AllianceFinanceData $data, string $direction): AllianceFinanceEntry
    {
        if (! $this->categories->exists($data->category)) {
            Log::warning('Unknown finance category encountered when persisting finance entry.', [
                'category' => $data->category,
                'direction' => $direction,
                'source_type' => $data->sourceType(),
                'source_id' => $data->sourceId(),
            ]);
        }

        $meta = $data->meta;
        $mergeMode = $meta['_merge_mode'] ?? null;
        unset($meta['_merge_mode']);

        if ($data->sourceType() && $data->sourceId()) {
            $existing = AllianceFinanceEntry::query()
                ->where('source_type', $data->sourceType())
                ->where('source_id', $data->sourceId())
                ->where('direction', $direction)
                ->where('category', $data->category)
                ->first();

            if ($existing) {
                if ($mergeMode === 'replace') {
                    $existing->fill($this->buildAttributes($data, $direction, $meta))->save();

                    return $existing;
                }

                return $existing;
            }
        }

        $normalizedDate = Carbon::parse($data->date)->startOfDay();
        $data->direction = $direction;
        $data->date = $normalizedDate;

        return AllianceFinanceEntry::create(
            $this->buildAttributes($data, $direction, $meta, $normalizedDate)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttributes(
        AllianceFinanceData $data,
        string $direction,
        array $meta,
        ?Carbon $dateOverride = null
    ): array {
        $dateValue = $dateOverride ?? Carbon::parse($data->date)->startOfDay();

        return [
            'date' => $dateValue->toDateString(),
            'direction' => $direction,
            'category' => $data->category,
            'description' => $data->description,
            'nation_id' => $data->nationId,
            'account_id' => $data->accountId,
            'source_type' => $data->sourceType(),
            'source_id' => $data->sourceId(),
            'money' => $data->money,
            'coal' => $data->coal,
            'oil' => $data->oil,
            'uranium' => $data->uranium,
            'iron' => $data->iron,
            'bauxite' => $data->bauxite,
            'lead' => $data->lead,
            'gasoline' => $data->gasoline,
            'munitions' => $data->munitions,
            'steel' => $data->steel,
            'aluminum' => $data->aluminum,
            'food' => $data->food,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function rangeQuery(CarbonInterface $from, CarbonInterface $to, array $filters): Builder
    {
        $query = AllianceFinanceEntry::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        return $this->applyFilters($query, $filters);
    }

    /**
     * @param  Builder<AllianceFinanceEntry>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AllianceFinanceEntry>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $direction = $filters['direction'] ?? null;
        if ($direction && in_array($direction, [AllianceFinanceEntry::DIRECTION_INCOME, AllianceFinanceEntry::DIRECTION_EXPENSE], true)) {
            $query->where('direction', $direction);
        }

        if (! empty($filters['categories']) && is_array($filters['categories'])) {
            $query->whereIn('category', $filters['categories']);
        }

        if (! empty($filters['nation_id'])) {
            $query->where('nation_id', $filters['nation_id']);
        }

        if (! empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function cacheKey(string $prefix, CarbonInterface $from, CarbonInterface $to, array $filters): string
    {
        ksort($filters);

        return sprintf(
            'finance:%s:%s:%s:%s',
            $prefix,
            $from->toDateString(),
            $to->toDateString(),
            md5(json_encode($filters))
        );
    }
}
