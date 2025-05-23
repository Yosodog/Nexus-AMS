<?php


namespace App\Services;

use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\Nation;
use Illuminate\Support\Facades\Log;

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
            $amount = (float) $record->$field;
            $rate = (float) $bracket->$field;

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
     * Enroll a nation in Direct Deposit and update their in-game tax bracket.
     */
    public function enroll(Nation $nation, Account $account): void
    {
        // TODO: implement mutation to set DD tax ID and persist enrollment
    }

    /**
     * Disenroll a nation from Direct Deposit and revert their tax bracket.
     */
    public function disenroll(Nation $nation): void
    {
        // TODO: lookup previous tax ID and revert tax bracket
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
}