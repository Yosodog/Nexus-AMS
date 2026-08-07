<?php

namespace App\Services\Loans;

use App\Models\Loan;
use Illuminate\Support\Carbon;

class LoanPaymentCalculator
{
    public function weeklyPayment(float $amount, float $interestRate, int $termWeeks): float
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

    public function scheduledPayment(Loan $loan): float
    {
        $configured = (float) ($loan->scheduled_weekly_payment ?? 0);

        if ($configured > 0) {
            return round($configured, 2);
        }

        return $this->weeklyPayment(
            (float) $loan->amount,
            (float) ($loan->interest_rate ?? 0),
            (int) ($loan->term_weeks ?? 0),
        );
    }

    public function weeklyInterest(float $remainingBalance, float $interestRate): float
    {
        $rate = $interestRate / 100;

        if ($rate <= 0 || $remainingBalance <= 0) {
            return 0.0;
        }

        return round($remainingBalance * $rate, 2);
    }

    /**
     * @return array<int, array{week: int, due_date: string|null, opening_balance: float, payment: float, interest: float, principal: float, closing_balance: float}>
     */
    public function amortizationSchedule(Loan $loan, int $paymentCycleDays): array
    {
        $principal = (float) $loan->amount;
        $rate = ((float) ($loan->interest_rate ?? 0)) / 100;
        $term = max(0, (int) ($loan->term_weeks ?? 0));
        $scheduled = $this->scheduledPayment($loan);

        if ($principal <= 0 || $term <= 0) {
            return [];
        }

        $rows = [];
        $balance = $principal;
        $firstDueDate = $loan->approved_at
            ? Carbon::parse($loan->approved_at)->startOfDay()->addDays($paymentCycleDays)
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
                'due_date' => $firstDueDate?->copy()->addDays(($week - 1) * $paymentCycleDays)->toDateString(),
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
}
