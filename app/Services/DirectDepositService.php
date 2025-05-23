<?php


namespace App\Services;

use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\Nation;
use Illuminate\Support\Facades\Log;
use Throwable;

class DirectDepositService
{
    /**
     * @var int
     */
    public int $ddTaxId;

    /**
     * @param SettingService $settings
     * @param AccountService $accountService
     */
    public function __construct(
        protected SettingService $settings,
        protected AccountService $accountService,
    ) {
        $this->ddTaxId = SettingService::getDirectDepositId();
    }

    /**
     * @param BankRecord $record
     * @return BankRecord
     */
    public function process(BankRecord $record): BankRecord
    {
        if ($record->tax_id !== $this->ddTaxId) {
            return $record; // Not a DD tax record
        }

        $nation = Nation::find($record->sender_id);
        if (!$nation) {
            Log::warning("DirectDeposit: Nation not found for BankRecord ID {$record->id}");
            return $record;
        }

        $bracket = $this->getApplicableBracket($nation);
        $account = $this->getDepositAccount($nation);
        $fields = PWHelperService::resources();

        $deposit = [];
        $retained = [];

        foreach ($fields as $field) {
            $amount = (float)$record->$field;
            $rate = (float)$bracket->$field;

            $taxed = round($amount * ($rate / 100), 2);
            $kept = round($amount - $taxed, 2);

            $retained[$field] = $taxed;
            $deposit[$field] = $kept;
        }

        // Apply deposit directly to the account without logging a manual transaction
        foreach ($deposit as $resource => $amount) {
            $account->{$resource} += $amount;
        }

        $account->save();

        // Log it in direct_deposit_logs
        DirectDepositLog::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'bank_record_id' => $record->id,
            ...$deposit,
        ]);

        // Update the BankRecord to only include retained (taxed) values
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

    /**
     * @param Nation $nation
     * @param Account $account
     * @return void
     */
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
        $mutation = new TaxBracketService();
        $mutation->id = $ddTaxId;
        $mutation->target_id = $nation->id;
        $mutation->send();
    }

    /**
     * @param Nation $nation
     * @return void
     */
    public function disenroll(Nation $nation): void
    {
        $enrollment = DirectDepositEnrollment::where('nation_id', $nation->id)->first();

        if (!$enrollment) {
            return;
        }

        $targetTaxId = $enrollment->previous_tax_id;
        $fallbackTaxId = (int)$this->settings->get('direct_deposit_fallback_tax_id');

        // Attempt to assign the previous tax bracket
        try {
            $mutation = new TaxBracketService();
            $mutation->id = $targetTaxId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        } catch (Throwable $e) {
            // Fallback if failure
            Log::warning(
                "Failed to assign previous tax ID {$targetTaxId} for nation {$nation->id}, retrying with fallback."
            );

            $fallbackMutation = new TaxBracketService();
            $fallbackMutation->id = $fallbackTaxId;
            $fallbackMutation->target_id = $nation->id;
            $fallbackMutation->send();
        }

        // Delete enrollment
        $enrollment->delete();
    }
}