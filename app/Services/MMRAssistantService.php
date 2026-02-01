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
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Support\Collection;

final readonly class MMRAssistantService
{
    public function __construct(
        private SettingService $settings,
        private TradePriceService $prices,
        private DB $db,
    ) {}

    /**
     * Compute a purchase plan from after-tax cash.
     * Returns: ['total_spend' => float, 'lines' => [res => ['pct','ppu','spend','qty']], 'account' => Account|null]
     */
    public function plan(Nation $nation, float $afterTaxCash): array
    {
        $result = ['total_spend' => 0.0, 'lines' => [], 'account' => null];

        if (! $this->settings::getMMRAssistantEnabled()) {
            return $result;
        }

        /** @var MMRConfig|null $config */
        $config = MMRConfig::where('nation_id', $nation->id)->first();
        if (! $config || ! $config->enabled) {
            return $result;
        }

        $mmrAccount = $config->account; // requires relation on MMRConfig: account()
        if (! $mmrAccount) {
            return $result;
        }

        /** @var Collection<string,MMRSetting> $resourceSettings */
        $resourceSettings = MMRSetting::query()->orderBy('resource')->get()->keyBy('resource');
        $allResources = collect(PWHelperService::resources(false));
        $pricesWithSurcharge = $this->prices->get24hAverageWithSurcharge(); // [resource => price]

        $totalSpend = 0.0;
        $lines = [];

        foreach ($allResources as $res) {
            $setting = $resourceSettings[$res] ?? null;
            $price = (float) ($pricesWithSurcharge[$res] ?? 0.0);
            $pctWhole = (float) ($config->getAttribute("{$res}_pct") ?? 0.0);

            if (! $setting || ! $setting->enabled || $pctWhole <= 0.0 || $price <= 0.0) {
                $lines[$res] = ['pct' => $pctWhole, 'ppu' => $price, 'spend' => 0.0, 'qty' => 0.0];

                continue;
            }

            $spend = round($afterTaxCash * ($pctWhole / 100.0), 2);
            $qty = $price > 0 ? round($spend / $price, 2) : 0.0;

            $lines[$res] = [
                'pct' => $pctWhole,
                'ppu' => $price,
                'spend' => $spend,
                'qty' => $qty,
            ];

            $totalSpend += $spend;
        }

        $result['total_spend'] = round($totalSpend, 2);
        $result['lines'] = $lines;
        $result['account'] = $mmrAccount;

        return $result;
    }

    /**
     * Apply a previously computed plan: credit resources and write a log.
     * IMPORTANT: This does NOT subtract money; caller must have withheld cash from the DD deposit.
     */
    public function applyPlan(Account $mmrAccount, array $plan): ?MMRAssistantPurchase
    {
        $totalSpend = (float) ($plan['total_spend'] ?? 0.0);
        $lines = $plan['lines'] ?? [];

        if ($totalSpend <= 0.0 || empty($lines)) {
            return null;
        }

        $log = $this->db->transaction(function () use ($mmrAccount, $totalSpend, $lines) {
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

            foreach ($lines as $res => $line) {
                $log->setAttribute($res, (float) $line['qty']);
                $log->setAttribute("{$res}_ppu", (float) $line['ppu'] ?: null);
            }

            $log->save();

            return $log;
        });

        if ($log) {
            $this->dispatchMmrExpenseEvent($mmrAccount, $totalSpend, $lines, $log);
        }

        return $log;
    }

    /**
     * @param  array<string, array<string, float>>  $lines
     */
    private function dispatchMmrExpenseEvent(
        Account $account,
        float $totalSpend,
        array $lines,
        MMRAssistantPurchase $log
    ): void {
        if ($totalSpend <= 0.0) {
            return;
        }

        $resourceQuantities = [];
        foreach (PWHelperService::resources(false) as $resource) {
            $resourceQuantities[$resource] = (float) ($lines[$resource]['qty'] ?? 0.0);
        }

        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'mmr_expense',
            description: "MMR Assistant purchase for account {$account->name}",
            date: now(),
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
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }
}
