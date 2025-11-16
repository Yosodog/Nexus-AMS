<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceIncomeOccurred;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Nation;
use App\Notifications\LoanNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct() {}

    public function applyForLoan(Nation $nation, Account $account, float $amount, int $termLength): Loan
    {
        // Create the loan record
        return Loan::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'term_weeks' => $termLength,
            'status' => 'pending',
            'remaining_balance' => $amount,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function validateLoanEligibility(Nation $nation, Account $account): bool
    {
        $validator = new NationEligibilityValidator($nation);
        $validator->validateAllianceMembership();

        // Loan specific validation
        if ($nation->id != $account->nation_id) {
            throw ValidationException::withMessages([
                'account_id' => "You don't own that account",
            ]);
        }

        return true; // No exceptions thrown, so it's gonna return true that they're eligible
    }

    /**
     * Approves a loan, updates the account balance, and notifies the user.
     */
    public function approveLoan(Loan $loan, float $amount, float $interestRate, int $termWeeks, Nation $nation): Loan
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
            $account = Account::findOrFail($loan->account_id);
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

    public function denyLoan(Loan $loan, Nation $nation): void
    {
        $loan->update(['status' => 'denied']);

        $nation->notify(new LoanNotification($nation->id, $loan, 'denied'));
    }

    public function processWeeklyPayments(): void
    {
        $today = now()->toDateString();
        $loans = Loan::where('next_due_date', $today)->where('status', 'approved')->get();

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
    public function calculateWeeklyPayment(Loan $loan): float
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

    private function getEarlyPaymentsSinceLastDue(Loan $loan): float
    {
        return $loan->payments()
            ->where('payment_date', '>=', $loan->next_due_date->subDays(7))
            ->sum('amount');
    }

    /**
     * Withdraw funds from an account and update the loan balance.
     */
    private function withdrawFromAccount(Account $account, float $amount, Loan $loan): void
    {
        $interestRate = $loan->interest_rate / 100;
        $interestPaid = round($amount * $interestRate, 2);
        $principalPaid = $amount - $interestPaid;

        $loanPayment = DB::transaction(function () use ($loan, $account, $amount, $principalPaid, $interestPaid) {
            // Deduct the payment from the account
            $adjustment = [
                'money' => -$amount,
                'note' => "Loan Payment: \${$amount} withdrawn",
            ];
            AccountService::adjustAccountBalance($account, $adjustment, null, null);

            // Log the loan payment
            $payment = LoanPayment::create([
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

            return $payment;
        });

        if ($loanPayment) {
            $this->dispatchLoanInterestEvent($loan, $account, $loanPayment, $interestPaid);
        }
    }

    public function markLoanAsPaid(Loan $loan): void
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
     * @throws ValidationException
     */
    public function repayLoan(Loan $loan, Account $account, float $amount): void
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

        $loanPayment = DB::transaction(function () use ($loan, $account, $amount) {
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
            $payment = LoanPayment::create([
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

            return [$payment, $interestPaid];
        });

        if (is_array($loanPayment)) {
            [$paymentModel, $interestPaid] = $loanPayment;
            $this->dispatchLoanInterestEvent($loan, $account, $paymentModel, (float) $interestPaid);
        }
    }

    public function processLoanPayment(Loan $loan): void
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

    private function dispatchLoanInterestEvent(
        Loan $loan,
        Account $account,
        LoanPayment $payment,
        float $interestPaid
    ): void {
        if ($interestPaid <= 0) {
            return;
        }

        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_INCOME,
            category: 'loan_interest',
            description: "Loan interest payment recorded for Loan #{$loan->id}",
            date: now(),
            nationId: $loan->nation_id,
            accountId: $account->id,
            source: $payment,
            money: $interestPaid,
            meta: [
                'loan_id' => $loan->id,
                'loan_payment_id' => $payment->id,
            ]
        );

        event(new AllianceIncomeOccurred($financeData->toArray()));
    }
}
