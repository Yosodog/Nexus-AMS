<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Nation;
use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\FeatureTestCase;

class LoanServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::setLoanPaymentsEnabled(true);
        Cache::forever('alliances:membership:ids', [777]);
    }

    public function test_calculate_weekly_payment_returns_zero_interest_installment_amount(): void
    {
        $loan = new Loan([
            'amount' => 1200,
            'interest_rate' => 0,
            'term_weeks' => 12,
        ]);

        $this->assertSame(100.0, app(LoanService::class)->calculateWeeklyPayment($loan));
    }

    public function test_calculate_weekly_payment_returns_amortized_amount_for_interest_bearing_loan(): void
    {
        $loan = new Loan([
            'amount' => 1000,
            'interest_rate' => 10,
            'term_weeks' => 10,
        ]);

        $this->assertSame(162.75, app(LoanService::class)->calculateWeeklyPayment($loan));
    }

    public function test_preview_payment_breakdown_applies_interest_before_principal(): void
    {
        $loan = new Loan([
            'remaining_balance' => 200,
            'accrued_interest_due' => 50,
            'scheduled_weekly_payment' => 100,
            'amount' => 300,
            'interest_rate' => 10,
            'term_weeks' => 3,
            'status' => 'approved',
        ]);

        $breakdown = app(LoanService::class)->previewPaymentBreakdown($loan, 125);

        $this->assertSame([
            'amount' => 125.0,
            'interest' => 50.0,
            'principal' => 75.0,
            'remaining_after' => 125.0,
            'accrued_interest_after' => 0.0,
        ], $breakdown);
    }

    public function test_build_amortization_schedule_returns_expected_rows(): void
    {
        $loan = new Loan([
            'amount' => 200,
            'interest_rate' => 0,
            'term_weeks' => 2,
            'scheduled_weekly_payment' => 100,
            'approved_at' => Carbon::parse('2026-01-01'),
        ]);

        $schedule = app(LoanService::class)->buildAmortizationSchedule($loan);

        $this->assertCount(2, $schedule);
        $this->assertSame(1, $schedule[0]['week']);
        $this->assertSame('2026-01-08', $schedule[0]['due_date']);
        $this->assertSame(100.0, $schedule[0]['principal']);
        $this->assertSame(0.0, $schedule[0]['interest']);
        $this->assertSame(0.0, $schedule[1]['closing_balance']);
    }

    public function test_calculate_current_amount_due_includes_virtual_overdue_cycle_adjustments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 12:00:00'));

        $loan = Loan::query()->create([
            'nation_id' => $this->createNationAndAccount()->id,
            'account_id' => Account::query()->sole()->id,
            'amount' => 500,
            'remaining_balance' => 500,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 100,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
            'interest_rate' => 10,
            'term_weeks' => 5,
            'status' => 'approved',
            'approved_at' => now()->subDays(14),
            'next_due_date' => now()->startOfDay()->subDay(),
        ]);

        try {
            $due = app(LoanService::class)->calculateCurrentAmountDue($loan->fresh(), now()->startOfDay());
            $this->assertSame(100.0, $due);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_get_current_cycle_progress_reports_paid_amounts_for_open_cycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 12:00:00'));
        $nation = $this->createNationAndAccount();
        $account = Account::query()->sole();

        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 500,
            'remaining_balance' => 400,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 100,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
            'interest_rate' => 10,
            'term_weeks' => 5,
            'status' => 'approved',
            'approved_at' => now()->subDays(10),
            'next_due_date' => now()->startOfDay()->addDays(3),
        ]);

        LoanPayment::query()->create([
            'loan_id' => $loan->id,
            'account_id' => $account->id,
            'amount' => 35,
            'principal_paid' => 30,
            'interest_paid' => 5,
            'payment_date' => now()->subDay(),
        ]);

        try {
            $progress = app(LoanService::class)->getCurrentCycleProgress($loan->fresh());
            $this->assertSame(35.0, $progress['paid_this_cycle']);
            $this->assertSame(65.0, $progress['remaining_to_scheduled']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_validate_loan_eligibility_rejects_foreign_accounts(): void
    {
        $nation = $this->createNationAndAccount();
        $foreignNation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);
        $foreignAccount = new Account;
        $foreignAccount->nation_id = $foreignNation->id;
        $foreignAccount->name = 'Foreign';
        $foreignAccount->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("You don't own that account");

        app(LoanService::class)->validateLoanEligibility($nation, $foreignAccount);
    }

    public function test_approve_loan_rejects_non_pending_status_before_mutation(): void
    {
        $nation = $this->createNationAndAccount();
        $account = Account::query()->where('nation_id', $nation->id)->firstOrFail();
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 500,
            'remaining_balance' => 500,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
            'interest_rate' => 10,
            'term_weeks' => 5,
            'status' => 'denied',
            'pending_key' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending loans can be approved.');

        app(LoanService::class)->approveLoan($loan, 500, 10, 5);
    }

    public function test_deny_loan_rejects_non_pending_status_before_mutation(): void
    {
        $nation = $this->createNationAndAccount();
        $account = Account::query()->where('nation_id', $nation->id)->firstOrFail();
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 500,
            'remaining_balance' => 500,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
            'interest_rate' => 10,
            'term_weeks' => 5,
            'status' => 'approved',
            'pending_key' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending loans can be denied.');

        app(LoanService::class)->denyLoan($loan);
    }

    private function createNationAndAccount(): Nation
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        return $nation;
    }
}
