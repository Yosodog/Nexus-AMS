<?php


namespace App\Services;

use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\Nation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DirectDepositService
{
    public function __construct(
        protected SettingService $settings,
        protected AccountService $accountService,
    ) {
    }

    /**
     * Process a BankRecord using Direct Deposit.
     * Adjusts retained taxes and deposits remaining balance.
     */
    public function process(BankRecord $record): BankRecord
    {
        // TODO: implement tax split and deposit logic
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