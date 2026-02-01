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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class LoanService
{
    /**
     * @throws ValidationException
     */
    private function ensureAccountNotFrozen(Account $account): void
    {
        if ($account->frozen) {
            throw ValidationException::withMessages([
                'account' => ['This account is frozen. Withdrawals are disabled.'],
            ]);
        }
    }

    public function applyForLoan(Nation $nation, Account $account, float $amount, int $termLength): Loan
    {
        // Create the loan record
        $loan = Loan::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'term_weeks' => $termLength,
            'status' => 'pending',
            'remaining_balance' => $amount,
        ]);

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'loans',
            action: 'loan_application_submitted',
            outcome: 'success',
            severity: 'info',
            subject: $loan,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $nation->id,
                    'amount' => $amount,
                    'term_weeks' => $termLength,
                ],
            ],
            message: 'Loan application submitted.'
        );

        return $loan;
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
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $loan->nation_id,
            context: 'approve your own loan request'
        );

        $updatedLoan = DB::transaction(function () use ($loan, $interestRate, $termWeeks, $amount, $nation) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            // Update the loan details
            $lockedLoan->update([
                'interest_rate' => $interestRate,
                'amount' => $amount,
                'remaining_balance' => $amount,
                'term_weeks' => $termWeeks,
                'status' => 'approved',
                'approved_at' => now(),
                'next_due_date' => now()->addDays(7), // First payment due in 7 days
            ]);

            // Fetch the recipient account
            $account = Account::findOrFail($lockedLoan->account_id);
            $adminId = Auth::id();
            $ipAddress = Request::ip();

            // Adjust account balance (Deposit loan funds)
            $adjustment = [
                'money' => $amount,
                'note' => "Loan Approved: \${$amount} deposited (Term: {$termWeeks} weeks, Interest: {$interestRate}%)",
            ];

            AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress);

            // Send loan approval notification
            $nation->notify(new LoanNotification($nation->id, $lockedLoan->fresh(), 'approved'));

            return $lockedLoan;
        });

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'loans',
            action: 'loan_approved',
            outcome: 'success',
            severity: 'info',
            subject: $updatedLoan,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $updatedLoan->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $updatedLoan->nation_id,
                    'amount' => $amount,
                    'interest_rate' => $interestRate,
                    'term_weeks' => $termWeeks,
                ],
            ],
            message: 'Loan approved.'
        );

        return $updatedLoan;
    }

    public function denyLoan(Loan $loan, Nation $nation): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $loan->nation_id,
            context: 'deny your own loan request'
        );

        DB::transaction(function () use ($loan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $lockedLoan->update(['status' => 'denied']);
        });

        $nation->notify(new LoanNotification($nation->id, $loan, 'denied'));

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'loans',
            action: 'loan_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $loan,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $loan->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $loan->nation_id,
                ],
            ],
            message: 'Loan denied.'
        );
    }

    public function shiftLoanDueDatesForPausedPeriod(Carbon $pausedAt, Carbon $resumedAt): int
    {
        $updated = 0;

        Loan::query()
            ->where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->whereNotNull('next_due_date')
            ->chunkById(200, function ($loans) use ($pausedAt, $resumedAt, &$updated) {
                foreach ($loans as $loan) {
                    $approvedAt = $loan->approved_at ? Carbon::parse($loan->approved_at) : null;
                    $baseline = $approvedAt && $approvedAt->greaterThan($pausedAt) ? $approvedAt : $pausedAt;
                    $days = $baseline->diffInDays($resumedAt);

                    if ($days <= 0) {
                        continue;
                    }

                    $loan->update([
                        'next_due_date' => $loan->next_due_date->copy()->addDays($days),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    public function processWeeklyPayments(): void
    {
        if (! SettingService::isLoanPaymentsEnabled()) {
            return;
        }

        $today = now()->toDateString();
        $loans = Loan::where('status', 'approved')
            ->where('remaining_balance', '>', 0)
            ->whereDate('next_due_date', '<=', $today)
            ->get();

        foreach ($loans as $loan) {
            $weeklyPayment = $this->calculateWeeklyPayment($loan);
            $nation = $loan->nation;

            // Get total early payments made since last due date
            $earlyPayments = $this->getEarlyPaymentsSinceLastDue($loan);

            // If early payments fully cover this week's payment, skip it
            if ($earlyPayments >= $weeklyPayment) {
                DB::transaction(function () use ($loan, $weeklyPayment) {
                    $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
                    $lockedLoan->update(['next_due_date' => $this->calculateNextDueDate($lockedLoan)]);
                    $lockedLoan->nation->notify(
                        new LoanNotification($lockedLoan->nation_id, $lockedLoan, 'early_payment_applied', $weeklyPayment)
                    );
                });

                continue;
            }

            // Reduce the weekly payment by early payments
            $amountDue = max(0, $weeklyPayment - $earlyPayments);

            // Try to withdraw the reduced amount from the primary account
            $primaryAccount = $loan->account;
            if ($primaryAccount && $primaryAccount->money >= $amountDue) {
                try {
                    $this->withdrawFromAccount($primaryAccount, $amountDue, $loan);

                    continue;
                } catch (ValidationException $e) {
                    // If the primary account is frozen, fall back to alternate options below
                }
            }

            // Try another account if the primary doesn't have enough funds
            $alternateAccount = $nation->accounts()
                ->where('frozen', false)
                ->where('money', '>=', $amountDue)
                ->orderBy('money', 'desc')
                ->first();
            if ($alternateAccount) {
                try {
                    $this->withdrawFromAccount($alternateAccount, $amountDue, $loan);

                    continue;
                } catch (ValidationException $e) {
                    // If the alternate account is frozen, treat like insufficient funds below
                }
            }

            // If still not enough funds, mark as missed payment
            DB::transaction(function () use ($loan) {
                $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
                $lockedLoan->update(['status' => 'missed']);
                $lockedLoan->nation->notify(new LoanNotification($lockedLoan->nation_id, $lockedLoan, 'missed_payment'));
            });
        }
    }

    /**
     * Calculates the weekly loan payment.
     */
    public function calculateWeeklyPayment(Loan $loan): float
    {
        if (! $loan->term_weeks || $loan->term_weeks <= 0 || $loan->amount <= 0) {
            return 0.0;
        }

        $r = ($loan->interest_rate ?? 0) / 100; // Convert weekly interest rate to decimal
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
        if (! $loan->next_due_date) {
            return 0.0;
        }

        return $loan->payments()
            ->where('payment_date', '>=', $loan->next_due_date->copy()->subDays(7))
            ->sum('amount');
    }

    /**
     * Withdraw funds from an account and update the loan balance.
     */
    private function withdrawFromAccount(Account $account, float $amount, Loan $loan): void
    {
        $this->ensureAccountNotFrozen($account);

        $loanPayment = DB::transaction(function () use ($loan, $account, $amount) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            [$interestPaid, $principalPaid] = $this->splitPaymentAmount($lockedLoan, $amount);

            // Deduct the payment from the account
            $adjustment = [
                'money' => -$amount,
                'note' => "Loan Payment: \${$amount} withdrawn",
            ];
            AccountService::adjustAccountBalance($account, $adjustment, null, null);

            // Log the loan payment
            $payment = LoanPayment::create([
                'loan_id' => $lockedLoan->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'principal_paid' => $principalPaid,
                'interest_paid' => $interestPaid,
            ]);

            // Update loan balance
            $lockedLoan->update([
                'remaining_balance' => max(0, $lockedLoan->remaining_balance - $principalPaid),
                'next_due_date' => $this->calculateNextDueDate($lockedLoan),
            ]);

            // Send loan payment success notification
            $lockedLoan->nation->notify(
                new LoanNotification($lockedLoan->nation_id, $lockedLoan->fresh(), 'payment_success', $amount)
            );

            // If the loan is fully paid, mark it as completed
            if ($lockedLoan->remaining_balance <= 0) {
                $this->markLoanAsPaid($lockedLoan);
            }

            return $payment;
        });

        if ($loanPayment) {
            app(AuditLogger::class)->recordAfterCommit(
                category: 'loans',
                action: 'loan_payment_posted',
                outcome: 'success',
                severity: 'info',
                subject: $loanPayment,
                context: [
                    'related' => [
                        ['type' => 'Loan', 'id' => (string) $loan->id, 'role' => 'loan'],
                        ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $loan->nation_id,
                        'amount' => $loanPayment->amount,
                        'principal_paid' => $loanPayment->principal_paid,
                        'interest_paid' => $loanPayment->interest_paid,
                    ],
                ],
                message: 'Loan payment posted.'
            );

            $this->dispatchLoanInterestEvent($loan, $account, $loanPayment, $loanPayment->interest_paid);
        }
    }

    public function markLoanAsPaid(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $lockedLoan->update([
                'status' => 'paid',
                'remaining_balance' => 0, // Always just ensure it's set to 0
            ]);

            // Notify the nation that the loan has been marked as paid
            $lockedLoan->nation->notify(new LoanNotification($lockedLoan->nation_id, $lockedLoan->fresh(), 'paid'));
        });

        app(AuditLogger::class)->recordAfterCommit(
            category: 'loans',
            action: 'loan_marked_paid',
            outcome: 'success',
            severity: 'info',
            subject: $loan,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $loan->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $loan->nation_id,
                ],
            ],
            message: 'Loan marked as paid.'
        );
    }

    /**
     * @throws ValidationException
     */
    public function repayLoan(Loan $loan, Account $account, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Repayment amount must be greater than zero.']);
        }

        $this->ensureAccountNotFrozen($account);

        if ($account->money < $amount) {
            throw ValidationException::withMessages(['account' => 'Insufficient funds in selected account.']);
        }

        $loanPayment = DB::transaction(function () use ($loan, $account, $amount) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($amount > $lockedLoan->remaining_balance) {
                throw ValidationException::withMessages(
                    ['amount' => 'You tried to pay more than what was left on the loan.']
                );
            }

            // Deduct the payment from the account
            $adjustment = [
                'money' => -$amount,
                'note' => "Early Loan Payment: \${$amount} paid towards loan ID {$loan->id}",
            ];
            AccountService::adjustAccountBalance($account, $adjustment, Auth::user()->id, Request::ip());

            [$interestPaid, $principalPaid] = $this->splitPaymentAmount($lockedLoan, $amount);

            // Log the loan payment in loan_payments table
            $payment = LoanPayment::create([
                'loan_id' => $lockedLoan->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'principal_paid' => $principalPaid,
                'interest_paid' => $interestPaid,
                'payment_date' => now(),
            ]);

            // Reduce loan balance
            $lockedLoan->update([
                'remaining_balance' => max(0, $lockedLoan->remaining_balance - $principalPaid),
            ]);

            // Send loan payment success notification
            $lockedLoan->nation->notify(
                new LoanNotification($lockedLoan->nation_id, $lockedLoan->fresh(), 'payment_success', $amount)
            );

            // If the loan is fully paid, mark as completed
            if ($lockedLoan->remaining_balance <= 0) {
                $this->markLoanAsPaid($lockedLoan);
            }

            return [$payment, $interestPaid];
        });

        if (is_array($loanPayment)) {
            [$paymentModel, $interestPaid] = $loanPayment;

            app(AuditLogger::class)->recordAfterCommit(
                category: 'loans',
                action: 'loan_payment_posted',
                outcome: 'success',
                severity: 'info',
                subject: $paymentModel,
                context: [
                    'related' => [
                        ['type' => 'Loan', 'id' => (string) $loan->id, 'role' => 'loan'],
                        ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $loan->nation_id,
                        'amount' => $paymentModel->amount,
                        'principal_paid' => $paymentModel->principal_paid,
                        'interest_paid' => $paymentModel->interest_paid,
                        'payment_type' => 'early',
                    ],
                ],
                message: 'Loan payment posted.'
            );

            $this->dispatchLoanInterestEvent($loan, $account, $paymentModel, (float) $interestPaid);
        }
    }

    public function processLoanPayment(Loan $loan): void
    {
        if (! SettingService::isLoanPaymentsEnabled()) {
            return;
        }

        $weeklyPayment = $this->calculateWeeklyPayment($loan);
        $nation = $loan->nation;

        // Get total early payments since last due date
        $earlyPayments = $this->getEarlyPaymentsSinceLastDue($loan);

        // If early payments fully cover this week's payment, skip it
        if ($earlyPayments >= $weeklyPayment) {
            DB::transaction(function () use ($loan, $weeklyPayment) {
                $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
                $lockedLoan->update(['next_due_date' => $this->calculateNextDueDate($lockedLoan)]);
                $lockedLoan->nation->notify(
                    new LoanNotification($lockedLoan->nation_id, $lockedLoan, 'early_payment_applied', $weeklyPayment)
                );
            });

            return;
        }

        // Reduce the weekly payment by early payments
        $amountDue = max(0, $weeklyPayment - $earlyPayments);

        // Try to withdraw the reduced amount from the primary account
        $primaryAccount = $loan->account;
        if ($primaryAccount && $primaryAccount->money >= $amountDue) {
            try {
                $this->withdrawFromAccount($primaryAccount, $amountDue, $loan);

                return;
            } catch (ValidationException $e) {
                // Attempt alternate accounts if the primary account is frozen
            }
        }

        // Try another account if the primary doesn't have enough funds
        $alternateAccount = $nation->accounts()
            ->where('frozen', false)
            ->where('money', '>=', $amountDue)
            ->orderBy('money', 'desc')
            ->first();
        if ($alternateAccount) {
            try {
                $this->withdrawFromAccount($alternateAccount, $amountDue, $loan);

                return;
            } catch (ValidationException $e) {
                // Fall through to mark the payment as missed if alternate account is frozen
            }
        }

        // If still not enough funds, mark as missed payment
        DB::transaction(function () use ($loan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $lockedLoan->update(['status' => 'missed']);
            $lockedLoan->nation->notify(new LoanNotification($lockedLoan->nation_id, $lockedLoan, 'missed_payment'));
        });
    }

    private function calculateNextDueDate(Loan $loan): Carbon
    {
        $baseDate = $loan->next_due_date ?? now();

        return $baseDate->copy()->addDays(7);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function splitPaymentAmount(Loan $loan, float $amount): array
    {
        $rate = ($loan->interest_rate ?? 0) / 100;
        $interestDue = $rate > 0 ? round(max(0, $loan->remaining_balance) * $rate, 2) : 0.0;
        $interestPaid = min($amount, $interestDue);
        $principalPaid = max(0.0, $amount - $interestPaid);

        return [$interestPaid, $principalPaid];
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

    /**
     * Count pending loan applications.
     */
    public function countPending(): int
    {
        return Loan::where('status', 'pending')->count();
    }
}
