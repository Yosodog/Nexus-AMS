<?php

namespace App\Services;

use App\Models\Accounts;
use App\Models\Loans;
use App\Models\Nations;
use App\Notifications\LoanNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct()
    {
    }

    /**
     * @param Nations $nation
     * @param Accounts $account
     * @param float $amount
     * @param int $termLength
     * @return Loans
     */
    public function applyForLoan(Nations $nation, Accounts $account, float $amount, int $termLength): Loans
    {
        // Create the loan record
        return Loans::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'term_weeks' => $termLength,
            'status' => 'pending',
        ]);
    }

    /**
     * @param Nations $nation
     * @param Accounts $account
     * @return bool
     * @throws ValidationException
     */
    public function validateLoanEligibility(Nations $nation, Accounts $account): bool
    {
        $validator = new NationEligibilityValidator($nation);
        $validator->validateAllianceMembership();

        // Loan specific validation
        if ($nation->id != $account->nation_id) {
            throw ValidationException::withMessages([
                'account_id' => "You don't own that account"
            ]);
        }

        return true; // No exceptions thrown, so it's gonna return true that they're eligible
    }

    /**
     * Approves a loan, updates the account balance, and notifies the user.
     *
     * @param Loans $loan
     * @param float $amount
     * @param float $interestRate
     * @param int $termWeeks
     * @param Nations $nation
     * @return Loans
     */
    public function approveLoan(Loans $loan, float $amount, float $interestRate, int $termWeeks, Nations $nation): Loans
    {
        return DB::transaction(function () use ($loan, $interestRate, $termWeeks, $amount, $nation) {
            // Update the loan details
            $loan->update([
                'interest_rate' => $interestRate,
                'amount' => $amount,
                'term_weeks' => $termWeeks,
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            // Fetch the recipient account
            $account = Accounts::findOrFail($loan->account_id);
            $adminId = Auth::id();
            $ipAddress = Request::ip();

            // Adjust account balance (Deposit loan funds)
            $adjustment = [
                'money' => $amount,
                'note' => "Loan Approved: \${$amount} deposited (Term: {$termWeeks} weeks, Interest: {$interestRate}%)",
            ];

            AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress);

            // Send loan approval notification
            $nation->notify(new LoanNotification($nation->id, $loan->fresh(), 'approved'));

            return $loan;
        });
    }

    /**
     * @param Loans $loan
     * @param Nations $nation
     * @return void
     */
    public function denyLoan(Loans $loan, Nations $nation): void
    {
        $loan->update(['status' => 'denied']);

        $nation->notify(new LoanNotification($nation->id, $loan, 'denied'));

    }
}