<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceIncomeOccurred;
use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\Nation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class DirectDepositService
{
    public int $ddTaxId;

    public function __construct(
        protected SettingService $settings,
        protected AccountService $accountService,
    ) {
        $this->ddTaxId = SettingService::getDirectDepositId();
    }

    public function process(BankRecord $record): BankRecord
    {
        if ($record->tax_id != $this->ddTaxId) {
            return $record; // Not a DD tax record
        }

        $nation = Nation::find($record->sender_id);
        if (! $nation) {
            Log::warning("DirectDeposit: Nation not found for BankRecord ID {$record->id}");

            return $record;
        }

        $bracket = $this->getApplicableBracket($nation);
        if (! $bracket) {
            $fallbackTaxId = SettingService::getDirectDepositFallbackId();
            Log::warning(
                "DirectDeposit: No tax bracket configured for nation {$nation->id}; using fallback tax ID {$fallbackTaxId}"
            );

            // Bail out and let the standard tax bracket handle this record.
            $record->tax_id = $fallbackTaxId;

            return $record;
        }
        $ddAccount = $this->getDepositAccount($nation);
        $fields = PWHelperService::resources();

        $deposit = []; // after-tax to member
        $retained = []; // alliance taxes

        foreach ($fields as $field) {
            $amount = (float) $record->$field;
            $rate = (float) $bracket->$field;

            $taxed = round($amount * ($rate / 100), 2);
            $kept = round($amount - $taxed, 2);

            $retained[$field] = $taxed;
            $deposit[$field] = $kept;
        }

        // Preserve the original after-tax numbers for the DD log (pre‑MMR)
        $originalDeposit = $deposit;

        // ---- MMR: compute plan from after-tax cash (money only)
        $afterTaxCash = (float) ($originalDeposit['money'] ?? 0.0);
        $plan = app(MMRAssistantService::class)->plan($nation, $afterTaxCash);

        $mmrTotalSpend = (float) ($plan['total_spend'] ?? 0.0);
        $mmrAccount = $plan['account'];

        // Reduce the member's actual cash deposit by the MMR spend
        if ($mmrTotalSpend > 0.0) {
            $deposit['money'] = max(0.0, round($deposit['money'] - $mmrTotalSpend, 2));
        }

        // Apply the reduced deposit to the DD account
        foreach ($deposit as $resource => $amount) {
            $ddAccount->{$resource} += $amount;
        }
        $ddAccount->save();

        // Log the DD event with the *original* after-tax values (so the player sees what they earned)
        $log = DirectDepositLog::create([
            'nation_id' => $nation->id,
            'account_id' => $ddAccount->id,
            'bank_record_id' => $record->id,
            ...$originalDeposit,
        ]);

        if ($mmrTotalSpend > 0.0) {
            $this->dispatchMmrContributionEvent($nation, $mmrAccount, $mmrTotalSpend, $record, $log, $plan);
        }

        // Apply MMR plan: credit resources on the configured MMR account
        if ($mmrTotalSpend > 0.0 && $mmrAccount) {
            try {
                app(MMRAssistantService::class)->applyPlan($mmrAccount, $plan);
            } catch (Throwable $e) {
                Log::warning(
                    "MMR Assistant apply failed for nation {$nation->id} on BankRecord {$record->id}: {$e->getMessage()}"
                );
            }
        }

        // IMPORTANT: Return the *retained* (tax) values for the tax record
        foreach ($fields as $field) {
            $record->$field = $retained[$field];
        }

        return $record;
    }

    /**
     * Determine which tax bracket applies to a nation by city count.
     */
    public function getApplicableBracket(Nation $nation): ?DirectDepositTaxBracket
    {
        return DirectDepositTaxBracket::where('city_number', $nation->num_cities)->first()
            ?? DirectDepositTaxBracket::where('city_number', 0)->first();
    }

    /**
     * Get the account used for DD deposits or fallback/create a new one.
     */
    public function getDepositAccount(Nation $nation): Account
    {
        $enrollment = DirectDepositEnrollment::where('nation_id', $nation->id)->first();

        if ($enrollment) {
            return $enrollment->account;
        }

        $fallback = $nation->accounts()->first();

        if ($fallback) {
            return $fallback;
        }

        return $this->accountService->createDefaultForNation($nation);
    }

    private function dispatchMmrContributionEvent(
        Nation $nation,
        ?Account $mmrAccount,
        float $mmrSpend,
        BankRecord $record,
        DirectDepositLog $log,
        array $plan
    ): void {
        if ($mmrSpend <= 0.0) {
            return;
        }

        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_INCOME,
            category: 'mmr_income',
            description: "MMR Assistant withholding for {$nation->nation_name}",
            date: Carbon::parse($record->date),
            nationId: $nation->id,
            accountId: $mmrAccount?->id,
            source: $log,
            money: $mmrSpend,
            meta: [
                'bank_record_id' => $record->id,
                'plan' => $plan['lines'] ?? [],
            ]
        );

        event(new AllianceIncomeOccurred($financeData->toArray()));
    }

    public function enroll(Nation $nation, Account $account): void
    {
        $ddTaxId = $this->ddTaxId;
        $currentTaxId = $nation->tax_id;

        // Determine previous tax ID
        $previousTaxId = ($currentTaxId === $ddTaxId)
            ? SettingService::getDirectDepositFallbackId()
            : $currentTaxId;

        // Save enrollment
        DirectDepositEnrollment::updateOrCreate(
            ['nation_id' => $nation->id],
            [
                'account_id' => $account->id,
                'previous_tax_id' => $previousTaxId,
                'enrolled_at' => now(),
            ]
        );

        // Queue GraphQL mutation to assign DD bracket
        $mutation = new TaxBracketService;
        $mutation->id = $ddTaxId;
        $mutation->target_id = $nation->id;
        $mutation->send();
    }

    public function disenroll(Nation $nation): void
    {
        $enrollment = DirectDepositEnrollment::where('nation_id', $nation->id)->first();

        if (! $enrollment) {
            return;
        }

        $targetTaxId = $enrollment->previous_tax_id;
        $fallbackTaxId = SettingService::getDirectDepositFallbackId();

        // Attempt to assign the previous tax bracket
        try {
            $mutation = new TaxBracketService;
            $mutation->id = $targetTaxId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        } catch (Throwable $e) {
            // Fallback if failure
            Log::warning(
                "Failed to assign previous tax ID {$targetTaxId} for nation {$nation->id}, retrying with fallback."
            );

            $fallbackMutation = new TaxBracketService;
            $fallbackMutation->id = $fallbackTaxId;
            $fallbackMutation->target_id = $nation->id;
            $fallbackMutation->send();
        }

        // Delete enrollment
        $enrollment->delete();
    }
}
