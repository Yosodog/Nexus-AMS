<?php

namespace Tests\Unit\Services\Loans;

use App\Models\Loan;
use App\Services\Loans\LoanPaymentCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoanPaymentCalculatorTest extends TestCase
{
    /**
     * @return array<string, array{float, float, int, float}>
     */
    public static function weeklyPaymentCases(): array
    {
        return [
            'empty principal' => [0, 10, 12, 0.0],
            'invalid term' => [1200, 10, 0, 0.0],
            'zero interest' => [1200, 0, 12, 100.0],
            'amortized' => [1000, 10, 10, 162.75],
        ];
    }

    #[DataProvider('weeklyPaymentCases')]
    public function test_it_calculates_weekly_payments(
        float $amount,
        float $interestRate,
        int $termWeeks,
        float $expected,
    ): void {
        $calculator = new LoanPaymentCalculator;

        $this->assertSame($expected, $calculator->weeklyPayment($amount, $interestRate, $termWeeks));
    }

    public function test_configured_scheduled_payment_takes_precedence_over_term_calculation(): void
    {
        $loan = new Loan([
            'amount' => 1000,
            'interest_rate' => 10,
            'term_weeks' => 10,
            'scheduled_weekly_payment' => 175.499,
        ]);

        $this->assertSame(175.5, (new LoanPaymentCalculator)->scheduledPayment($loan));
    }

    public function test_it_calculates_weekly_interest_without_negative_values(): void
    {
        $calculator = new LoanPaymentCalculator;

        $this->assertSame(12.35, $calculator->weeklyInterest(123.45, 10));
        $this->assertSame(0.0, $calculator->weeklyInterest(-100, 10));
        $this->assertSame(0.0, $calculator->weeklyInterest(100, 0));
    }

    public function test_amortization_schedule_caps_the_final_payment_and_preserves_due_dates(): void
    {
        $loan = new Loan([
            'amount' => 200,
            'interest_rate' => 0,
            'term_weeks' => 3,
            'scheduled_weekly_payment' => 90,
            'approved_at' => Carbon::parse('2026-01-01'),
        ]);

        $schedule = (new LoanPaymentCalculator)->amortizationSchedule($loan, 7);

        $this->assertCount(3, $schedule);
        $this->assertSame('2026-01-08', $schedule[0]['due_date']);
        $this->assertSame('2026-01-22', $schedule[2]['due_date']);
        $this->assertSame(20.0, $schedule[2]['payment']);
        $this->assertSame(0.0, $schedule[2]['closing_balance']);
    }

    public function test_amortization_schedule_has_a_no_date_fallback(): void
    {
        $loan = new Loan([
            'amount' => 100,
            'interest_rate' => 0,
            'term_weeks' => 1,
            'scheduled_weekly_payment' => 100,
        ]);

        $schedule = (new LoanPaymentCalculator)->amortizationSchedule($loan, 7);

        $this->assertNull($schedule[0]['due_date']);
    }
}
