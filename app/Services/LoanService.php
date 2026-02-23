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
    private const DAYS_PER_PAYMENT_CYCLE = 7;

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
        $loan = Loan::create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'term_weeks' => $termLength,
            'status' => 'pending',
            'remaining_balance' => $amount,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
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

        if ($nation->id !== $account->nation_id) {
            throw ValidationException::withMessages([
                'account_id' => "You don't own that account",
            ]);
        }

        return true;
    }

    public function approveLoan(Loan $loan, float $amount, float $interestRate, int $termWeeks): Loan
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $loan->nation_id,
            context: 'approve your own loan request'
        );

        $updatedLoan = DB::transaction(function () use ($loan, $interestRate, $termWeeks, $amount) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($lockedLoan->status !== 'pending') {
                throw ValidationException::withMessages([
                    'loan' => 'Only pending loans can be approved.',
                ]);
            }

            $scheduledPayment = $this->calculateWeeklyPaymentFromInputs($amount, $interestRate, $termWeeks);

            $lockedLoan->update([
                'interest_rate' => $interestRate,
                'amount' => $amount,
                'remaining_balance' => $amount,
                'term_weeks' => $termWeeks,
                'status' => 'approved',
                'approved_at' => now(),
                'next_due_date' => now()->addDays(self::DAYS_PER_PAYMENT_CYCLE),
                'weekly_interest_paid' => 0,
                'scheduled_weekly_payment' => $scheduledPayment,
                'past_due_amount' => 0,
                'accrued_interest_due' => 0,
            ]);

            $account = Account::findOrFail($lockedLoan->account_id);
            $adminId = Auth::id();
            $ipAddress = Request::ip();

            AccountService::adjustAccountBalance($account, [
                'money' => $amount,
                'note' => "Loan Approved: \${$amount} deposited (Term: {$termWeeks} weeks, Weekly Interest: {$interestRate}%)",
            ], $adminId, $ipAddress);

            $borrower = $lockedLoan->nation()->firstOrFail();
            $borrower->notify(new LoanNotification($borrower->id, $lockedLoan->fresh(), 'approved'));

            return $lockedLoan->fresh();
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
                    'scheduled_weekly_payment' => $updatedLoan->scheduled_weekly_payment,
                ],
            ],
            message: 'Loan approved.'
        );

        return $updatedLoan;
    }

    public function denyLoan(Loan $loan): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $loan->nation_id,
            context: 'deny your own loan request'
        );

        $updatedLoan = DB::transaction(function () use ($loan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($lockedLoan->status !== 'pending') {
                throw ValidationException::withMessages([
                    'loan' => 'Only pending loans can be denied.',
                ]);
            }

            $lockedLoan->update(['status' => 'denied']);

            return $lockedLoan->fresh();
        });

        $updatedLoan->nation->notify(new LoanNotification($updatedLoan->nation_id, $updatedLoan, 'denied'));

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
            ->whereIn('status', ['approved', 'missed'])
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

        $today = now()->startOfDay();

        $loans = Loan::query()
            ->whereIn('status', ['approved', 'missed'])
            ->where('remaining_balance', '>', 0)
            ->where(function ($query) use ($today) {
                $query->where('past_due_amount', '>', 0)
                    ->orWhere('accrued_interest_due', '>', 0)
                    ->orWhereDate('next_due_date', '<=', $today->toDateString());
            })
            ->with(['nation.accounts', 'account'])
            ->get();

        foreach ($loans as $loan) {
            $cyclesAccrued = 0;

            $amountDue = DB::transaction(function () use ($loan, $today, &$cyclesAccrued) {
                $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
                $cyclesAccrued = $this->accrueDueCycles($lockedLoan, $today);

                $due = $this->calculateCurrentAmountDue($lockedLoan, $today, false);

                if ($due <= 0 && $lockedLoan->status === 'missed') {
                    $lockedLoan->update(['status' => 'approved']);
                }

                return $due;
            });

            if ($amountDue <= 0) {
                continue;
            }

            $primaryAccount = $loan->account;
            $paid = $primaryAccount ? $this->withdrawFromAccount($primaryAccount, $amountDue, $loan, true) : false;

            if (! $paid) {
                $alternateAccount = $loan->nation
                    ->accounts()
                    ->where('frozen', false)
                    ->where('id', '!=', $loan->account_id)
                    ->orderBy('money', 'desc')
                    ->get()
                    ->first(function (Account $account) use ($amountDue, $loan) {
                        return $account->money >= $amountDue && $this->withdrawFromAccount($account, $amountDue, $loan, true);
                    });

                $paid = $alternateAccount instanceof Account;
            }

            if (! $paid) {
                DB::transaction(function () use ($loan, $cyclesAccrued) {
                    $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
                    $lockedLoan->update(['status' => 'missed']);

                    if ($cyclesAccrued > 0) {
                        $lockedLoan->nation->notify(
                            new LoanNotification(
                                $lockedLoan->nation_id,
                                $lockedLoan->fresh(),
                                'missed_payment',
                                $this->calculateCurrentAmountDue($lockedLoan->fresh(), now()->startOfDay())
                            )
                        );
                    }
                });
            }
        }
    }

    public function calculateWeeklyPayment(Loan $loan): float
    {
        return $this->calculateWeeklyPaymentFromInputs(
            (float) $loan->amount,
            (float) ($loan->interest_rate ?? 0),
            (int) ($loan->term_weeks ?? 0)
        );
    }

    public function calculateCurrentAmountDue(
        Loan $loan,
        ?Carbon $asOfDate = null,
        bool $includeVirtualAccruals = true
    ): float {
        if (! SettingService::isLoanPaymentsEnabled()) {
            return 0.0;
        }

        if ($loan->remaining_balance <= 0 || $loan->status === 'paid') {
            return 0.0;
        }

        $asOf = ($asOfDate ?? now())->copy()->startOfDay();
        $scheduledPayment = $this->calculateScheduledPayment($loan);
        $virtualAdjustments = $includeVirtualAccruals
            ? $this->calculateVirtualCycleAdjustments($loan, $asOf, $scheduledPayment)
            : ['past_due_increment' => 0.0, 'accrued_interest_increment' => 0.0];

        $pastDueAmount = max(0.0, (float) $loan->past_due_amount + (float) $virtualAdjustments['past_due_increment']);
        $accruedInterestDue = max(
            0.0,
            (float) $loan->accrued_interest_due + (float) $virtualAdjustments['accrued_interest_increment']
        );

        $minimumDue = max($pastDueAmount, $accruedInterestDue);
        $totalOwed = max(0.0, (float) $loan->remaining_balance + $accruedInterestDue);

        return round(min($minimumDue, $totalOwed), 2);
    }

    /**
     * @return array{amount: float, interest: float, principal: float, remaining_after: float, accrued_interest_after: float}
     */
    public function previewPaymentBreakdown(Loan $loan, float $amount, ?Carbon $asOfDate = null): array
    {
        $requested = round(max(0.0, $amount), 2);
        $asOf = ($asOfDate ?? now())->copy()->startOfDay();
        $virtualAdjustments = $this->calculateVirtualCycleAdjustments(
            $loan,
            $asOf,
            $this->calculateScheduledPayment($loan)
        );

        $accruedInterestDue = max(
            0.0,
            (float) $loan->accrued_interest_due + (float) $virtualAdjustments['accrued_interest_increment']
        );

        $totalOwed = max(0.0, (float) $loan->remaining_balance + $accruedInterestDue);
        $appliedAmount = round(min($requested, $totalOwed), 2);

        $interestPaid = round(min($appliedAmount, $accruedInterestDue), 2);
        $principalPaid = round(max(0.0, $appliedAmount - $interestPaid), 2);

        return [
            'amount' => $appliedAmount,
            'interest' => $interestPaid,
            'principal' => $principalPaid,
            'remaining_after' => round(max(0.0, (float) $loan->remaining_balance - $principalPaid), 2),
            'accrued_interest_after' => round(max(0.0, $accruedInterestDue - $interestPaid), 2),
        ];
    }

    /**
     * @return array<int, array{week: int, due_date: string|null, opening_balance: float, payment: float, interest: float, principal: float, closing_balance: float}>
     */
    public function buildAmortizationSchedule(Loan $loan): array
    {
        $principal = (float) $loan->amount;
        $rate = ((float) ($loan->interest_rate ?? 0)) / 100;
        $term = max(0, (int) ($loan->term_weeks ?? 0));
        $scheduled = $this->calculateScheduledPayment($loan);

        if ($principal <= 0 || $term <= 0) {
            return [];
        }

        $rows = [];
        $balance = $principal;
        $firstDueDate = $loan->approved_at
            ? Carbon::parse($loan->approved_at)->startOfDay()->addDays(self::DAYS_PER_PAYMENT_CYCLE)
            : null;

        for ($week = 1; $week <= $term; $week++) {
            if ($balance <= 0) {
                break;
            }

            $openingBalance = round($balance, 2);
            $interest = round($rate > 0 ? $openingBalance * $rate : 0.0, 2);
            $payment = round(min($scheduled, $openingBalance + $interest), 2);
            $principalPaid = round(max(0.0, $payment - $interest), 2);
            $closingBalance = round(max(0.0, $openingBalance - $principalPaid), 2);

            $rows[] = [
                'week' => $week,
                'due_date' => $firstDueDate ? $firstDueDate->copy()->addDays(($week - 1) * self::DAYS_PER_PAYMENT_CYCLE)->toDateString() : null,
                'opening_balance' => $openingBalance,
                'payment' => $payment,
                'interest' => $interest,
                'principal' => $principalPaid,
                'closing_balance' => $closingBalance,
            ];

            $balance = $closingBalance;
        }

        return $rows;
    }

    /**
     * @return array{paid_this_cycle: float, remaining_to_scheduled: float, cycle_start: string|null, cycle_end: string|null}
     */
    public function getCurrentCycleProgress(Loan $loan): array
    {
        if (! $loan->next_due_date) {
            return [
                'paid_this_cycle' => 0.0,
                'remaining_to_scheduled' => 0.0,
                'cycle_start' => null,
                'cycle_end' => null,
            ];
        }

        $cycleEnd = $loan->next_due_date->copy()->startOfDay();
        $cycleStart = $cycleEnd->copy()->subDays(self::DAYS_PER_PAYMENT_CYCLE);
        $scheduled = $this->calculateScheduledPayment($loan);

        $paidThisCycle = (float) LoanPayment::query()
            ->where('loan_id', $loan->id)
            ->where('payment_date', '>=', $cycleStart)
            ->where('payment_date', '<', $cycleEnd)
            ->sum('amount');

        return [
            'paid_this_cycle' => round($paidThisCycle, 2),
            'remaining_to_scheduled' => round(max(0.0, $scheduled - $paidThisCycle), 2),
            'cycle_start' => $cycleStart->toDateString(),
            'cycle_end' => $cycleEnd->toDateString(),
        ];
    }

    public function markLoanAsPaid(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $lockedLoan->update([
                'status' => 'paid',
                'remaining_balance' => 0,
                'past_due_amount' => 0,
                'accrued_interest_due' => 0,
                'weekly_interest_paid' => 0,
            ]);

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

        if (! in_array($loan->status, ['approved', 'missed'], true)) {
            throw ValidationException::withMessages(['loan_id' => 'This loan is not in a repayable state.']);
        }

        $this->ensureAccountNotFrozen($account);

        $requestedAmount = round($amount, 2);

        $loanPayment = DB::transaction(function () use ($loan, $account, $requestedAmount) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedLoan->status, ['approved', 'missed'], true)) {
                throw ValidationException::withMessages(['loan_id' => 'This loan is not in a repayable state.']);
            }

            $this->ensureAccountNotFrozen($lockedAccount);

            if (SettingService::isLoanPaymentsEnabled()) {
                $this->accrueDueCycles($lockedLoan, now()->startOfDay());
            }

            $maxPayable = round(max(0.0, (float) $lockedLoan->remaining_balance + (float) $lockedLoan->accrued_interest_due), 2);

            if ($requestedAmount > $maxPayable) {
                throw ValidationException::withMessages([
                    'amount' => 'You tried to pay more than what is currently owed on the loan.',
                ]);
            }

            if ($lockedAccount->money < $requestedAmount) {
                throw ValidationException::withMessages(['account' => 'Insufficient funds in selected account.']);
            }

            return $this->applyPaymentAndRecord(
                $lockedLoan,
                $lockedAccount,
                $requestedAmount,
                "Loan Payment: \${$requestedAmount} paid toward loan ID {$lockedLoan->id}",
                Auth::id(),
                Request::ip()
            );
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
                        'payment_type' => 'manual',
                    ],
                ],
                message: 'Loan payment posted.'
            );

            $this->dispatchLoanInterestEvent($loan, $account, $loanPayment, (float) $loanPayment->interest_paid);
        }
    }

    private function withdrawFromAccount(Account $account, float $amount, Loan $loan, bool $isScheduled): bool
    {
        try {
            $this->ensureAccountNotFrozen($account);
        } catch (ValidationException) {
            return false;
        }

        $payment = DB::transaction(function () use ($loan, $account, $amount, $isScheduled) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            $this->ensureAccountNotFrozen($lockedAccount);

            if (! in_array($lockedLoan->status, ['approved', 'missed'], true)) {
                return null;
            }

            if ($isScheduled) {
                $this->accrueDueCycles($lockedLoan, now()->startOfDay());
            }

            $dueAmount = $isScheduled
                ? $this->calculateCurrentAmountDue($lockedLoan, now()->startOfDay(), false)
                : $amount;

            $totalOwed = round(max(0.0, (float) $lockedLoan->remaining_balance + (float) $lockedLoan->accrued_interest_due), 2);
            $amountToApply = round(min($amount, $dueAmount, $totalOwed), 2);

            if ($amountToApply <= 0 || $lockedAccount->money < $amountToApply) {
                return null;
            }

            return $this->applyPaymentAndRecord(
                $lockedLoan,
                $lockedAccount,
                $amountToApply,
                "Loan Payment: \${$amountToApply} withdrawn",
                null,
                null
            );
        });

        if (! $payment instanceof LoanPayment) {
            return false;
        }

        app(AuditLogger::class)->recordAfterCommit(
            category: 'loans',
            action: 'loan_payment_posted',
            outcome: 'success',
            severity: 'info',
            subject: $payment,
            context: [
                'related' => [
                    ['type' => 'Loan', 'id' => (string) $loan->id, 'role' => 'loan'],
                    ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $loan->nation_id,
                    'amount' => $payment->amount,
                    'principal_paid' => $payment->principal_paid,
                    'interest_paid' => $payment->interest_paid,
                    'payment_type' => 'automatic',
                ],
            ],
            message: 'Loan payment posted.'
        );

        $this->dispatchLoanInterestEvent($loan, $account, $payment, (float) $payment->interest_paid);

        return true;
    }

    private function applyPaymentAndRecord(
        Loan $loan,
        Account $account,
        float $amount,
        string $note,
        ?int $actorId,
        ?string $ipAddress
    ): LoanPayment {
        $interestPaid = round(min($amount, max(0.0, (float) $loan->accrued_interest_due)), 2);
        $principalPaid = round(max(0.0, $amount - $interestPaid), 2);

        AccountService::adjustAccountBalance($account, [
            'money' => -$amount,
            'note' => $note,
        ], $actorId, $ipAddress);

        $payment = LoanPayment::create([
            'loan_id' => $loan->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'payment_date' => now(),
        ]);

        $remainingBalance = round(max(0.0, (float) $loan->remaining_balance - $principalPaid), 2);
        $accruedInterestDue = round(max(0.0, (float) $loan->accrued_interest_due - $interestPaid), 2);
        $pastDueAmount = round(max(0.0, (float) $loan->past_due_amount - $amount), 2);

        $status = $remainingBalance <= 0 && $accruedInterestDue <= 0
            ? 'paid'
            : ($pastDueAmount > 0 || $accruedInterestDue > 0 ? 'missed' : 'approved');

        $loan->update([
            'remaining_balance' => $remainingBalance,
            'accrued_interest_due' => $accruedInterestDue,
            'past_due_amount' => $pastDueAmount,
            'weekly_interest_paid' => 0,
            'status' => $status,
        ]);

        $freshLoan = $loan->fresh();

        $freshLoan->nation->notify(new LoanNotification($freshLoan->nation_id, $freshLoan, 'payment_success', $amount));

        if ($status === 'paid') {
            $freshLoan->nation->notify(new LoanNotification($freshLoan->nation_id, $freshLoan, 'paid'));
        }

        return $payment;
    }

    private function calculateWeeklyPaymentFromInputs(float $amount, float $interestRate, int $termWeeks): float
    {
        if ($termWeeks <= 0 || $amount <= 0) {
            return 0.0;
        }

        $rate = $interestRate / 100;

        if ($rate == 0.0) {
            return round($amount / $termWeeks, 2);
        }

        return round(($rate * $amount) / (1 - pow(1 + $rate, -$termWeeks)), 2);
    }

    private function calculateScheduledPayment(Loan $loan): float
    {
        $configured = (float) ($loan->scheduled_weekly_payment ?? 0);

        if ($configured > 0) {
            return round($configured, 2);
        }

        return $this->calculateWeeklyPayment($loan);
    }

    private function calculateWeeklyInterest(float $remainingBalance, float $interestRate): float
    {
        $rate = $interestRate / 100;

        if ($rate <= 0 || $remainingBalance <= 0) {
            return 0.0;
        }

        return round($remainingBalance * $rate, 2);
    }

    /**
     * @return array{past_due_increment: float, accrued_interest_increment: float}
     */
    private function calculateVirtualCycleAdjustments(Loan $loan, Carbon $asOfDate, float $scheduledPayment): array
    {
        if (! SettingService::isLoanPaymentsEnabled()) {
            return ['past_due_increment' => 0.0, 'accrued_interest_increment' => 0.0];
        }

        if (! $loan->next_due_date) {
            return ['past_due_increment' => 0.0, 'accrued_interest_increment' => 0.0];
        }

        $dueDate = $loan->next_due_date->copy()->startOfDay();
        if ($dueDate->greaterThan($asOfDate)) {
            return ['past_due_increment' => 0.0, 'accrued_interest_increment' => 0.0];
        }

        $pastDueIncrement = 0.0;
        $accruedInterestIncrement = 0.0;

        while ($dueDate->lessThanOrEqualTo($asOfDate)) {
            $cycleStart = $dueDate->copy()->subDays(self::DAYS_PER_PAYMENT_CYCLE);
            $paidThisCycle = (float) LoanPayment::query()
                ->where('loan_id', $loan->id)
                ->where('payment_date', '>=', $cycleStart)
                ->where('payment_date', '<', $dueDate)
                ->sum('amount');

            $cycleShortfall = max(0.0, $scheduledPayment - $paidThisCycle);
            $cycleInterest = $this->calculateLockedCycleInterest($loan, $cycleStart, $asOfDate);

            $pastDueIncrement += $cycleShortfall;
            $accruedInterestIncrement += $cycleInterest;

            $dueDate = $dueDate->copy()->addDays(self::DAYS_PER_PAYMENT_CYCLE);
        }

        return [
            'past_due_increment' => round($pastDueIncrement, 2),
            'accrued_interest_increment' => round($accruedInterestIncrement, 2),
        ];
    }

    private function accrueDueCycles(Loan $loan, Carbon $asOfDate): int
    {
        if (! SettingService::isLoanPaymentsEnabled()) {
            return 0;
        }

        if (! $loan->next_due_date || $loan->remaining_balance <= 0) {
            return 0;
        }

        $cycles = 0;
        $scheduled = $this->calculateScheduledPayment($loan);

        while ($loan->next_due_date && $loan->next_due_date->copy()->startOfDay()->lessThanOrEqualTo($asOfDate)) {
            $cycleEnd = $loan->next_due_date->copy()->startOfDay();
            $cycleStart = $cycleEnd->copy()->subDays(self::DAYS_PER_PAYMENT_CYCLE);

            $paidThisCycle = (float) LoanPayment::query()
                ->where('loan_id', $loan->id)
                ->where('payment_date', '>=', $cycleStart)
                ->where('payment_date', '<', $cycleEnd)
                ->sum('amount');

            $interest = $this->calculateLockedCycleInterest($loan, $cycleStart, $asOfDate);
            $cycleShortfall = max(0.0, $scheduled - $paidThisCycle);

            $loan->accrued_interest_due = round((float) $loan->accrued_interest_due + $interest, 2);
            $loan->past_due_amount = round((float) $loan->past_due_amount + $cycleShortfall, 2);
            $loan->next_due_date = $loan->next_due_date->copy()->addDays(self::DAYS_PER_PAYMENT_CYCLE);
            $loan->status = 'missed';
            $loan->weekly_interest_paid = 0;

            $cycles++;
        }

        if ($cycles > 0) {
            $loan->save();
        }

        return $cycles;
    }

    private function calculateLockedCycleInterest(Loan $loan, Carbon $cycleStart, Carbon $asOfDate): float
    {
        $openingBalance = $this->calculateCycleOpeningBalance($loan, $cycleStart, $asOfDate);

        return $this->calculateWeeklyInterest($openingBalance, (float) ($loan->interest_rate ?? 0));
    }

    private function calculateCycleOpeningBalance(Loan $loan, Carbon $cycleStart, Carbon $asOfDate): float
    {
        $principalPaidSinceCycleStart = (float) LoanPayment::query()
            ->where('loan_id', $loan->id)
            ->where('payment_date', '>=', $cycleStart)
            ->where('payment_date', '<', $asOfDate)
            ->sum('principal_paid');

        return round(max(0.0, (float) $loan->remaining_balance + $principalPaidSinceCycleStart), 2);
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

    public function countPending(): int
    {
        return Loan::where('status', 'pending')->count();
    }
}
