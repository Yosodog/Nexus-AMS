<?php

namespace App\Services;

use App\Models\Accounts;
use App\Models\LoanPayments;
use App\Models\Loans;
use App\Models\Nations;
use App\Notifications\LoanNotification;
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
            'remaining_balance' => $amount
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
                'next_due_date' => now()->addDays(7), // First payment due in 7 days
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

    /**
     * @return void
     */
    public function processWeeklyPayments(): void
    {
        $today = now()->toDateString();
        $loans = Loans::where('next_due_date', $today)->where('status', 'approved')->get();

        foreach ($loans as $loan) {
            $weeklyPayment = $this->calculateWeeklyPayment($loan);
            $nation = $loan->nation;

            // Get total early payments made since last due date
            $earlyPayments = $this->getEarlyPaymentsSinceLastDue($loan);

            // If early payments fully cover this week's payment, skip it
            if ($earlyPayments >= $weeklyPayment) {
                $loan->update(['next_due_date' => now()->addDays(7)]);
                $nation->notify(new LoanNotification($loan->nation_id, $loan, 'early_payment_applied'));
                continue;
            }

            // Reduce the weekly payment by early payments
            $amountDue = max(0, $weeklyPayment - $earlyPayments);

            // Try to withdraw the reduced amount from the primary account
            $primaryAccount = $loan->account;
            if ($primaryAccount && $primaryAccount->money >= $amountDue) {
                $this->withdrawFromAccount($primaryAccount, $amountDue, $loan);
                continue;
            }

            // Try another account if the primary doesn't have enough funds
            $alternateAccount = $nation->accounts()->where('money', '>=', $amountDue)->orderBy('money', 'desc')->first(
            );
            if ($alternateAccount) {
                $this->withdrawFromAccount($alternateAccount, $amountDue, $loan);
                continue;
            }

            // If still not enough funds, mark as missed payment
            $loan->update(['status' => 'missed']);
            $nation->notify(new LoanNotification($loan->nation_id, $loan, 'missed_payment'));
        }
    }

    /**
     * Calculates the weekly loan payment.
     */
    public function calculateWeeklyPayment(Loans $loan): float
    {
        $r = $loan->interest_rate / 100; // Convert interest rate to decimal
        $P = $loan->amount;
        $n = $loan->term_weeks;

        // If interest rate is 0, return simple division
        if ($r == 0) {
            return round($P / $n, 2);
        }

        return round(($r * $P) / (1 - pow(1 + $r, -$n)), 2);
    }

    /**
     * @param Loans $loan
     * @return float
     */
    private function getEarlyPaymentsSinceLastDue(Loans $loan): float
    {
        return $loan->payments()
            ->where('payment_date', '>=', $loan->next_due_date->subDays(7))
            ->sum('amount');
    }

    /**
     * Withdraw funds from an account and update the loan balance.
     */
    private function withdrawFromAccount(Accounts $account, float $amount, Loans $loan): void
    {
        $interestRate = $loan->interest_rate / 100;
        $interestPaid = round($amount * $interestRate, 2);
        $principalPaid = $amount - $interestPaid;

        DB::transaction(function () use ($loan, $account, $amount, $principalPaid, $interestPaid) {
            // Deduct the payment from the account
            $adjustment = [
                'money' => -$amount,
                'note' => "Loan Payment: \${$amount} withdrawn",
            ];
            AccountService::adjustAccountBalance($account, $adjustment, null, null);

            // Log the loan payment
            LoanPayments::create([
                'loan_id' => $loan->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'principal_paid' => $principalPaid,
                'interest_paid' => $interestPaid,
            ]);

            // Update loan balance
            $loan->update([
                'remaining_balance' => max(0, $loan->remaining_balance - $principalPaid),
                'next_due_date' => now()->addDays(7),
            ]);

            // Send loan payment success notification
            $loan->nation->notify(new LoanNotification($loan->nation_id, $loan->fresh(), 'payment_success', $amount));

            // If the loan is fully paid, mark it as completed
            if ($loan->remaining_balance <= 0) {
                $this->markLoanAsPaid($loan);
            }
        });
    }

    /**
     * @param Loans $loan
     * @return void
     */
    public function markLoanAsPaid(Loans $loan): void
    {
        DB::transaction(function () use ($loan) {
            $loan->update([
                'status' => 'paid',
                'remaining_balance' => 0, // Always just ensure it's set to 0
            ]);

            // Notify the nation that the loan has been marked as paid
            $loan->nation->notify(new LoanNotification($loan->nation_id, $loan->fresh(), 'paid'));
        });
    }

    /**
     * @param Loans $loan
     * @param Accounts $account
     * @param float $amount
     * @return void
     * @throws ValidationException
     */
    public function repayLoan(Loans $loan, Accounts $account, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Repayment amount must be greater than zero.']);
        }

        if ($account->money < $amount) {
            throw ValidationException::withMessages(['account' => 'Insufficient funds in selected account.']);
        }

        if ($amount > $loan->remaining_balance) {
            throw ValidationException::withMessages(
                ['amount' => 'You tried to pay more than what was left on the loan.']
            );
        }

        DB::transaction(function () use ($loan, $account, $amount) {
            // Deduct the payment from the account
            $adjustment = [
                'money' => -$amount,
                'note' => "Early Loan Payment: \${$amount} paid towards loan ID {$loan->id}",
            ];
            AccountService::adjustAccountBalance($account, $adjustment, Auth::user()->id, Request::ip());

            // Calculate interest and principal paid
            $interestRate = $loan->interest_rate / 100;
            $interestPaid = round($amount * $interestRate, 2);
            $principalPaid = $amount - $interestPaid;

            // Log the loan payment in loan_payments table
            LoanPayments::create([
                'loan_id' => $loan->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'principal_paid' => $principalPaid,
                'interest_paid' => $interestPaid,
                'payment_date' => now(),
            ]);

            // Reduce loan balance
            $loan->update([
                'remaining_balance' => max(0, $loan->remaining_balance - $principalPaid),
            ]);

            // Send loan payment success notification
            $loan->nation->notify(new LoanNotification($loan->nation_id, $loan->fresh(), 'payment_success', $amount));

            // If the loan is fully paid, mark as completed
            if ($loan->remaining_balance <= 0) {
                $this->markLoanAsPaid($loan);
            }
        });
    }

    /**
     * @param Loans $loan
     * @return void
     */
    public function processLoanPayment(Loans $loan): void
    {
        $weeklyPayment = $this->calculateWeeklyPayment($loan);
        $nation = $loan->nation;

        // Get total early payments since last due date
        $earlyPayments = $this->getEarlyPaymentsSinceLastDue($loan);

        // If early payments fully cover this week's payment, skip it
        if ($earlyPayments >= $weeklyPayment) {
            $loan->update(['next_due_date' => now()->addDays(7)]);
            $nation->notify(new LoanNotification($loan->nation_id, $loan, 'early_payment_applied'));
            return;
        }

        // Reduce the weekly payment by early payments
        $amountDue = max(0, $weeklyPayment - $earlyPayments);

        // Try to withdraw the reduced amount from the primary account
        $primaryAccount = $loan->account;
        if ($primaryAccount && $primaryAccount->balance >= $amountDue) {
            $this->withdrawFromAccount($primaryAccount, $amountDue, $loan);
            return;
        }

        // Try another account if the primary doesn't have enough funds
        $alternateAccount = $nation->accounts()->where('balance', '>=', $amountDue)->orderBy('balance', 'desc')->first(
        );
        if ($alternateAccount) {
            $this->withdrawFromAccount($alternateAccount, $amountDue, $loan);
            return;
        }

        // If still not enough funds, mark as missed payment
        $loan->update(['status' => 'missed']);
        $nation->notify(new LoanNotification($loan->nation_id, $loan, 'missed_payment'));
    }
}