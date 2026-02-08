<?php

namespace App\Services;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\MarketResource;
use App\Models\MarketTransaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketService
{
    public function __construct(
        protected TradePriceService $tradePriceService,
        protected AllianceMembershipService $membershipService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * @return Collection<int, MarketResource>
     */
    public function getMarketResources(bool $onlyEnabled = false): Collection
    {
        $this->ensureMarketResourcesExist();

        $query = MarketResource::query()->orderBy('resource');

        if ($onlyEnabled) {
            $query->where('is_enabled', true);
        }

        return $query->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEnabledMarketResourcesForUser(): array
    {
        return $this->getMarketResourcePricing($this->getMarketResources(true));
    }

    /**
     * @param  Collection<int, MarketResource>  $resources
     * @return array<int, array<string, mixed>>
     */
    public function getMarketResourcePricing(Collection $resources): array
    {
        $basePrices = $this->getBasePrices();

        return $resources->map(function (MarketResource $resource) use ($basePrices): array {
            $basePrice = $basePrices[$resource->resource] ?? 0;
            $finalPrice = $this->computeFinalPrice($resource->resource, (float) $resource->adjustment_percent, $basePrice);

            return [
                'id' => $resource->id,
                'resource' => $resource->resource,
                'is_enabled' => $resource->is_enabled,
                'adjustment_percent' => (float) $resource->adjustment_percent,
                'buy_cap_remaining' => (float) $resource->buy_cap_remaining,
                'base_price' => $basePrice,
                'final_price' => $finalPrice,
            ];
        })->values()->all();
    }

    public function computeFinalPrice(string $resource, float $adjustmentPercent, ?float $basePrice = null): float
    {
        if ($adjustmentPercent <= -100) {
            throw ValidationException::withMessages([
                'adjustment_percent' => 'Adjustment percent must be greater than -100.00.',
            ]);
        }

        $basePrice = $basePrice ?? Arr::get($this->getBasePrices(), $resource, 0);

        return round($basePrice * (1 + ($adjustmentPercent / 100)), 4);
    }

    public function sell(
        User $user,
        Account $account,
        string $resource,
        float $amount
    ): MarketTransaction {
        $this->assertMember($user);

        if (! in_array($resource, PWHelperService::resources(false), true)) {
            throw ValidationException::withMessages([
                'resource' => 'That resource cannot be traded on the alliance market.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be greater than 0.',
            ]);
        }

        if ($account->nation_id !== $user->nation_id) {
            throw new UserErrorException('You do not own that account.');
        }

        return DB::transaction(function () use ($user, $account, $resource, $amount): MarketTransaction {
            $marketResource = MarketResource::query()
                ->where('resource', $resource)
                ->lockForUpdate()
                ->first();

            if (! $marketResource || ! $marketResource->is_enabled) {
                throw ValidationException::withMessages([
                    'resource' => 'That resource is not currently buyable.',
                ]);
            }

            if ((float) $marketResource->buy_cap_remaining < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'That sale exceeds the remaining buy cap for this resource.',
                ]);
            }

            $capBefore = (float) $marketResource->buy_cap_remaining;

            $lockedAccount = Account::query()
                ->lockForUpdate()
                ->findOrFail($account->id);

            if ($lockedAccount->frozen) {
                throw ValidationException::withMessages([
                    'account' => 'This account is frozen. Sales are disabled.',
                ]);
            }

            if ((float) $lockedAccount->{$resource} < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Your account does not have enough of this resource.',
                ]);
            }

            $basePrice = Arr::get($this->getBasePrices(), $resource, 0);
            $finalPrice = $this->computeFinalPrice($resource, (float) $marketResource->adjustment_percent, $basePrice);
            $moneyPaid = round($amount * $finalPrice, 2);

            AccountService::adjustAccountBalance($lockedAccount, [
                'money' => $moneyPaid,
                $resource => -1 * $amount,
                'note' => 'Alliance market sale',
            ], null, null, [
                'market_resource_id' => $marketResource->id,
                'market_resource' => $resource,
                'adjustment_percent' => (float) $marketResource->adjustment_percent,
                'final_price' => $finalPrice,
            ]);

            $marketResource->buy_cap_remaining = (float) $marketResource->buy_cap_remaining - $amount;
            $marketResource->save();

            $marketTransaction = MarketTransaction::create([
                'user_id' => $user->id,
                'nation_id' => $user->nation_id,
                'account_id' => $lockedAccount->id,
                'resource' => $resource,
                'amount' => $amount,
                'adjustment_percent' => (float) $marketResource->adjustment_percent,
                'final_price' => $finalPrice,
                'money_paid' => $moneyPaid,
            ]);

            $this->auditLogger->recordAfterCommit(
                category: 'market',
                action: 'resource_sold',
                outcome: 'success',
                severity: 'info',
                subject: $marketTransaction,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $lockedAccount->id, 'role' => 'account'],
                        ['type' => 'MarketResource', 'id' => (string) $marketResource->id, 'role' => 'market_resource'],
                    ],
                    'data' => [
                        'resource' => $resource,
                        'amount' => $amount,
                        'base_price' => $basePrice,
                        'final_price' => $finalPrice,
                        'money_paid' => $moneyPaid,
                        'cap_before' => $capBefore,
                        'cap_after' => (float) $marketResource->buy_cap_remaining,
                    ],
                ],
                message: 'Alliance market sale completed.'
            );

            return $marketTransaction;
        });
    }

    /**
     * @return array<string, float>
     */
    public function getBasePrices(): array
    {
        $average = $this->tradePriceService->get24hAverage();
        $prices = [];

        foreach (PWHelperService::resources(false) as $resource) {
            $prices[$resource] = (float) ($average->{$resource} ?? 0);
        }

        return $prices;
    }

    /**
     * @return array{stats: array<string, mixed>, money_paid_chart: array<string, mixed>, volume_chart: array<string, mixed>}
     */
    public function getAdminMarketOverview(): array
    {
        $since = Carbon::now()->subDays(29)->startOfDay();
        $baseQuery = MarketTransaction::query()->where('created_at', '>=', $since);

        $volume = (float) (clone $baseQuery)->sum('amount');
        $totalPaid = (float) (clone $baseQuery)->sum('money_paid');
        $topResource = (clone $baseQuery)
            ->selectRaw('resource, SUM(money_paid) as total_paid')
            ->groupBy('resource')
            ->orderByDesc('total_paid')
            ->first();
        $totalRemainingCap = (float) MarketResource::query()->sum('buy_cap_remaining');

        $moneyByDay = (clone $baseQuery)
            ->selectRaw('DATE(created_at) as day, SUM(money_paid) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->pluck('total', 'day');

        $volumeRows = (clone $baseQuery)
            ->selectRaw('DATE(created_at) as day, resource, SUM(amount) as total')
            ->groupBy('day', 'resource')
            ->orderBy('day')
            ->get();

        $labels = collect(CarbonPeriod::create($since, Carbon::now()->startOfDay()))
            ->map(fn (Carbon $date) => $date->format('Y-m-d'))
            ->values()
            ->all();

        $moneySeries = array_map(fn (string $label): float => (float) ($moneyByDay[$label] ?? 0), $labels);

        $resourceKeys = $volumeRows->pluck('resource')->unique()->values()->all();
        $volumeLookup = [];

        foreach ($volumeRows as $row) {
            $volumeLookup[$row->resource][$row->day] = (float) $row->total;
        }

        $volumeDatasets = [];

        foreach ($resourceKeys as $resource) {
            $volumeDatasets[] = [
                'label' => ucfirst(str_replace('_', ' ', $resource)),
                'data' => array_map(fn (string $label): float => (float) ($volumeLookup[$resource][$label] ?? 0), $labels),
            ];
        }

        return [
            'stats' => [
                'volume' => $volume,
                'total_paid' => $totalPaid,
                'top_resource' => $topResource?->resource,
                'top_resource_paid' => (float) ($topResource?->total_paid ?? 0),
                'total_remaining_cap' => $totalRemainingCap,
            ],
            'money_paid_chart' => [
                'labels' => $labels,
                'data' => $moneySeries,
            ],
            'volume_chart' => [
                'labels' => $labels,
                'datasets' => $volumeDatasets,
            ],
        ];
    }

    protected function assertMember(User $user): void
    {
        $nation = $user->nation;

        if (! $nation || ! $this->membershipService->contains($nation->alliance_id)) {
            throw ValidationException::withMessages([
                'alliance' => 'You must be in the alliance to sell on the market.',
            ]);
        }

        if ($nation->alliance_position === 'APPLICANT' || $nation->alliance_position_id === 1) {
            throw ValidationException::withMessages([
                'alliance' => 'Applicants are not eligible to sell on the market.',
            ]);
        }

        if ((int) $nation->vacation_mode_turns > 0) {
            throw ValidationException::withMessages([
                'vacation_mode' => 'Your nation cannot sell while in vacation mode.',
            ]);
        }
    }

    protected function ensureMarketResourcesExist(): void
    {
        $resources = PWHelperService::resources(false);
        $existing = MarketResource::query()->pluck('resource')->all();
        $missing = array_diff($resources, $existing);

        if (empty($missing)) {
            return;
        }

        $payload = [];

        foreach ($missing as $resource) {
            $payload[] = [
                'resource' => $resource,
                'is_enabled' => false,
                'adjustment_percent' => 0,
                'buy_cap_remaining' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        MarketResource::query()->insertOrIgnore($payload);
    }
}
