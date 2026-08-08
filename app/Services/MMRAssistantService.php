<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRConfig;
use App\Models\MMRSetting;
use App\Models\Nation;
use App\Services\Economy\EconomyRules;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final readonly class MMRAssistantService
{
    private const MAX_PROJECTION_AGE_HOURS = 3;

    public function __construct(
        private SettingService $settings,
        private TradePriceService $prices,
        private NationProfitabilityService $profitability,
        private DB $db,
    ) {}

    /**
     * Compute a purchase plan from after-tax cash.
     *
     * @return array<string, mixed>
     */
    public function plan(Nation $nation, float $afterTaxCash): array
    {
        return $this->buildPlan($nation, $afterTaxCash);
    }

    /**
     * Compute the automatic plan for display without changing the saved allocation mode.
     *
     * @return array<string, mixed>
     */
    public function previewAutomaticPlan(Nation $nation, float $afterTaxCash): array
    {
        return $this->buildPlan($nation, $afterTaxCash, forceAutomatic: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(Nation $nation, float $afterTaxCash, bool $forceAutomatic = false): array
    {
        $result = $this->emptyPlan();

        if (! $this->settings::getMMRAssistantEnabled()) {
            return $result;
        }

        /** @var MMRConfig|null $config */
        $config = MMRConfig::where('nation_id', $nation->id)->first();
        if (! $config || ! $config->enabled) {
            return $result;
        }

        $mmrAccount = Account::query()
            ->whereKey($config->account_id)
            ->where('nation_id', $nation->id)
            ->where('frozen', false)
            ->first();

        if (! $mmrAccount) {
            return $result;
        }

        $automatic = $forceAutomatic || $config->auto_cover_resource_deficits;
        $result['mode'] = $automatic
            ? MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC
            : MMRAssistantPurchase::ALLOCATION_MODE_MANUAL;
        $result['account'] = $mmrAccount;

        /** @var Collection<string,MMRSetting> $resourceSettings */
        $resourceSettings = MMRSetting::query()->orderBy('resource')->get()->keyBy('resource');
        $allResources = PWHelperService::resources(false);
        $pricesWithSurcharge = $this->prices->get24hAverageWithSurcharge(); // [resource => price]

        if ($automatic) {
            return $this->buildAutomaticPlan(
                $nation,
                max(0.0, $afterTaxCash),
                $allResources,
                $resourceSettings,
                $pricesWithSurcharge,
                $result,
            );
        }

        return $this->buildManualPlan(
            $config,
            max(0.0, $afterTaxCash),
            $allResources,
            $resourceSettings,
            $pricesWithSurcharge,
            $result,
        );
    }

    /**
     * @param  array<int, string>  $allResources
     * @param  Collection<string, MMRSetting>  $resourceSettings
     * @param  array<string, float|int>  $pricesWithSurcharge
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildManualPlan(
        MMRConfig $config,
        float $afterTaxCash,
        array $allResources,
        Collection $resourceSettings,
        array $pricesWithSurcharge,
        array $result,
    ): array {
        $allocationTotal = collect($allResources)->sum(
            fn (string $resource): float => max(
                0.0,
                (float) ($config->getAttribute("{$resource}_pct") ?? 0.0)
            )
        );

        if ($allocationTotal > 100.0) {
            Log::warning('MMR Assistant: refusing manual plan with allocation over 100%.', [
                'nation_id' => $config->nation_id,
                'allocation_total' => $allocationTotal,
            ]);

            $result['status'] = 'invalid-allocation';

            return $result;
        }

        $totalSpend = 0.0;
        $remainingCash = round($afterTaxCash, 2);
        $lines = [];

        foreach ($allResources as $res) {
            $setting = $resourceSettings[$res] ?? null;
            $price = (float) ($pricesWithSurcharge[$res] ?? 0.0);
            $pctWhole = (float) ($config->getAttribute("{$res}_pct") ?? 0.0);

            if (! $setting || ! $setting->enabled || $pctWhole <= 0.0 || $price <= 0.0) {
                $lines[$res] = [
                    'pct' => $pctWhole,
                    'ppu' => $price,
                    'spend' => 0.0,
                    'qty' => 0.0,
                    'target_qty' => 0.0,
                    'daily_shortfall' => 0.0,
                    'coverage_pct' => null,
                    'purchasable' => false,
                ];

                continue;
            }

            $spend = min(
                round($afterTaxCash * ($pctWhole / 100.0), 2),
                $remainingCash,
            );
            $qty = $price > 0 ? round($spend / $price, 2) : 0.0;

            $lines[$res] = [
                'pct' => $pctWhole,
                'ppu' => $price,
                'spend' => $spend,
                'qty' => $qty,
                'target_qty' => 0.0,
                'daily_shortfall' => 0.0,
                'coverage_pct' => null,
                'purchasable' => true,
            ];

            $totalSpend += $spend;
            $remainingCash = max(0.0, round($remainingCash - $spend, 2));
        }

        $result['total_spend'] = round($totalSpend, 2);
        $result['lines'] = $lines;
        $result['status'] = 'manual';

        return $result;
    }

    /**
     * @param  array<int, string>  $allResources
     * @param  Collection<string, MMRSetting>  $resourceSettings
     * @param  array<string, float|int>  $pricesWithSurcharge
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildAutomaticPlan(
        Nation $nation,
        float $afterTaxCash,
        array $allResources,
        Collection $resourceSettings,
        array $pricesWithSurcharge,
        array $result,
    ): array {
        $projection = $this->profitability->getDailyTradeResourceShortfallProjection(
            $nation,
            self::MAX_PROJECTION_AGE_HOURS,
        );

        if ($projection === null) {
            $result['status'] = 'projection_unavailable';

            return $result;
        }

        $result['projection_calculated_at'] = $projection['calculated_at'];
        $lines = [];
        $targetSpend = 0.0;
        $hasDeficits = false;
        $unavailableResources = [];

        foreach ($allResources as $resource) {
            $setting = $resourceSettings[$resource] ?? null;
            $price = (float) ($pricesWithSurcharge[$resource] ?? 0.0);
            $dailyShortfall = max(0.0, (float) ($projection['shortfalls_per_day'][$resource] ?? 0.0));
            $targetQuantity = round($dailyShortfall / EconomyRules::TURNS_PER_DAY, 2);
            $purchasable = $targetQuantity > 0.0
                && $setting?->enabled
                && $price > 0.0;
            $requiredSpend = $price > 0.0 ? round($targetQuantity * $price, 2) : 0.0;

            $hasDeficits = $hasDeficits || $targetQuantity > 0.0;

            if ($targetQuantity > 0.0 && ! $purchasable) {
                $unavailableResources[] = $resource;
            }

            if ($purchasable) {
                $targetSpend += $requiredSpend;
            }

            $lines[$resource] = [
                'pct' => 0.0,
                'ppu' => $price,
                'spend' => 0.0,
                'qty' => 0.0,
                'target_qty' => $targetQuantity,
                'daily_shortfall' => $dailyShortfall,
                'required_spend' => $requiredSpend,
                'coverage_pct' => $targetQuantity > 0.0 ? 0.0 : 100.0,
                'purchasable' => $purchasable,
            ];
        }

        $targetSpend = round($targetSpend, 2);
        $scale = $targetSpend > 0.0
            ? min(1.0, $afterTaxCash / $targetSpend)
            : 0.0;
        $remainingCash = round($afterTaxCash, 2);
        $totalSpend = 0.0;

        foreach ($lines as $resource => &$line) {
            if (! $line['purchasable'] || $remainingCash <= 0.0) {
                continue;
            }

            $allocatedSpend = min((float) $line['required_spend'] * $scale, $remainingCash);
            $quantity = $scale >= 1.0
                ? (float) $line['target_qty']
                : min(
                    (float) $line['target_qty'],
                    $this->floorToResourcePrecision($allocatedSpend / (float) $line['ppu']),
                );
            $spend = min(round($quantity * (float) $line['ppu'], 2), $remainingCash);

            $line['qty'] = $quantity;
            $line['spend'] = $spend;
            $line['pct'] = $afterTaxCash > 0.0
                ? round(($spend / $afterTaxCash) * 100, 4)
                : 0.0;
            $line['coverage_pct'] = $line['target_qty'] > 0.0
                ? round(($quantity / (float) $line['target_qty']) * 100, 2)
                : 100.0;

            $remainingCash = round($remainingCash - $spend, 2);
            $totalSpend += $spend;
        }
        unset($line);

        if ($scale > 0.0 && $scale < 1.0 && $remainingCash > 0.0) {
            $this->allocateRoundingRemainder(
                $lines,
                $scale,
                $afterTaxCash,
                $remainingCash,
                $totalSpend,
            );
        }

        $result['status'] = match (true) {
            ! $hasDeficits => 'no_deficits',
            $targetSpend <= 0.0 => 'no_purchasable_deficits',
            default => 'available',
        };
        $result['target_spend'] = $targetSpend;
        $result['total_spend'] = round($totalSpend, 2);
        $result['coverage_pct'] = $targetSpend > 0.0
            ? round(($result['total_spend'] / $targetSpend) * 100, 2)
            : ($hasDeficits ? 0.0 : 100.0);
        $result['unavailable_resources'] = $unavailableResources;
        $result['lines'] = $lines;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlan(): array
    {
        return [
            'mode' => MMRAssistantPurchase::ALLOCATION_MODE_MANUAL,
            'status' => 'inactive',
            'total_spend' => 0.0,
            'target_spend' => 0.0,
            'coverage_pct' => null,
            'projection_calculated_at' => null,
            'unavailable_resources' => [],
            'lines' => [],
            'account' => null,
        ];
    }

    private function floorToResourcePrecision(float $quantity): float
    {
        return floor(($quantity + 0.0000001) * 100) / 100;
    }

    /**
     * Use pooled fractional remainders to buy minimum 0.01 units while staying as close
     * as possible to the proportional allocation and never exceeding available cash.
     *
     * @param  array<string, array<string, mixed>>  $lines
     */
    private function allocateRoundingRemainder(
        array &$lines,
        float $scale,
        float $afterTaxCash,
        float &$remainingCash,
        float &$totalSpend,
    ): void {
        $candidates = [];

        foreach ($lines as $resource => $line) {
            if (! $line['purchasable'] || $line['qty'] >= $line['target_qty']) {
                continue;
            }

            $unallocatedIdealSpend = max(
                0.0,
                ((float) $line['required_spend'] * $scale) - (float) $line['spend'],
            );

            if ($unallocatedIdealSpend > 0.000001) {
                $candidates[$resource] = $unallocatedIdealSpend;
            }
        }

        arsort($candidates, SORT_NUMERIC);

        foreach (array_keys($candidates) as $resource) {
            $line = &$lines[$resource];
            $nextQuantity = min(
                (float) $line['target_qty'],
                round((float) $line['qty'] + 0.01, 2),
            );
            $nextSpend = round($nextQuantity * (float) $line['ppu'], 2);
            $incrementalSpend = round($nextSpend - (float) $line['spend'], 2);

            if ($incrementalSpend <= 0.0 || $incrementalSpend > $remainingCash) {
                unset($line);

                continue;
            }

            $line['qty'] = $nextQuantity;
            $line['spend'] = $nextSpend;
            $line['pct'] = $afterTaxCash > 0.0
                ? round(($nextSpend / $afterTaxCash) * 100, 4)
                : 0.0;
            $line['coverage_pct'] = round(
                ($nextQuantity / (float) $line['target_qty']) * 100,
                2,
            );

            $remainingCash = round($remainingCash - $incrementalSpend, 2);
            $totalSpend += $incrementalSpend;
            unset($line);

            if ($remainingCash <= 0.0) {
                break;
            }
        }
    }

    /**
     * Apply a previously computed plan: credit resources and write a log.
     * IMPORTANT: This does NOT subtract money; caller must have withheld cash from the DD deposit.
     */
    public function applyPlan(
        Account $mmrAccount,
        array $plan,
        ?CarbonInterface $occurredAt = null,
    ): ?MMRAssistantPurchase {
        $totalSpend = (float) ($plan['total_spend'] ?? 0.0);
        $lines = $plan['lines'] ?? [];
        $allocationMode = in_array(($plan['mode'] ?? null), [
            MMRAssistantPurchase::ALLOCATION_MODE_MANUAL,
            MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC,
        ], true) ? $plan['mode'] : MMRAssistantPurchase::ALLOCATION_MODE_MANUAL;
        $projectionCalculatedAt = ($plan['projection_calculated_at'] ?? null) instanceof CarbonInterface
            ? $plan['projection_calculated_at']
            : null;

        if ($totalSpend <= 0.0 || empty($lines)) {
            return null;
        }

        $log = $this->db->transaction(function () use (
            $mmrAccount,
            $totalSpend,
            $lines,
            $allocationMode,
            $projectionCalculatedAt,
            $occurredAt,
        ) {
            // Credit resources
            foreach ($lines as $res => $line) {
                $qty = (float) $line['qty'];
                if ($qty > 0) {
                    $mmrAccount->increment($res, $qty);
                }
            }

            // Log purchase
            $log = new MMRAssistantPurchase;
            $log->account_id = $mmrAccount->id;
            $log->total_spent = $totalSpend;
            $log->allocation_mode = $allocationMode;
            $log->projection_calculated_at = $projectionCalculatedAt;

            foreach ($lines as $res => $line) {
                $log->setAttribute($res, (float) $line['qty']);
                $log->setAttribute("{$res}_ppu", (float) $line['ppu'] ?: null);
            }

            if ($occurredAt !== null) {
                $log->forceFill([
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);
            }

            $log->save();

            return $log;
        });

        if ($log) {
            $this->dispatchMmrExpenseEvent($mmrAccount, $totalSpend, $lines, $log, $occurredAt);
        }

        return $log;
    }

    /**
     * @param  array<string, array<string, mixed>>  $lines
     */
    private function dispatchMmrExpenseEvent(
        Account $account,
        float $totalSpend,
        array $lines,
        MMRAssistantPurchase $log,
        ?CarbonInterface $occurredAt,
    ): void {
        if ($totalSpend <= 0.0) {
            return;
        }

        $resourceQuantities = [];
        foreach (PWHelperService::resources(false) as $resource) {
            $resourceQuantities[$resource] = (float) ($lines[$resource]['qty'] ?? 0.0);
        }

        $eventTimestamp = $occurredAt !== null
            ? Carbon::instance($occurredAt)->copy()->utc()
            : ($log->created_at ?? now());

        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'mmr_expense',
            description: "MMR Assistant purchase for account {$account->name}",
            date: $eventTimestamp,
            nationId: $account->nation_id,
            accountId: $account->id,
            source: $log,
            money: 0, // Will always be 0. The "cost" to the alliance are the resources. Income is the money
            coal: $resourceQuantities['coal'] ?? 0.0,
            oil: $resourceQuantities['oil'] ?? 0.0,
            uranium: $resourceQuantities['uranium'] ?? 0.0,
            iron: $resourceQuantities['iron'] ?? 0.0,
            bauxite: $resourceQuantities['bauxite'] ?? 0.0,
            lead: $resourceQuantities['lead'] ?? 0.0,
            gasoline: $resourceQuantities['gasoline'] ?? 0.0,
            munitions: $resourceQuantities['munitions'] ?? 0.0,
            steel: $resourceQuantities['steel'] ?? 0.0,
            aluminum: $resourceQuantities['aluminum'] ?? 0.0,
            food: $resourceQuantities['food'] ?? 0.0,
            meta: [
                'plan' => $lines,
                'allocation_mode' => $log->allocation_mode,
                'projection_calculated_at' => $log->projection_calculated_at?->toAtomString(),
            ],
            occurredAt: $eventTimestamp,
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }
}
