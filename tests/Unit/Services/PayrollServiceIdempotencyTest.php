<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Nation;
use App\Models\PayrollGrade;
use App\Models\PayrollMember;
use App\Models\Transaction;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class PayrollServiceIdempotencyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_daily_payroll_does_not_credit_the_same_member_twice_for_one_run_date(): void
    {
        [$member, $account] = $this->createPayrollFixture();
        $service = $this->payrollService();
        $runDate = Carbon::parse('2026-06-05');

        $firstSummary = $service->runDailyPayroll($runDate);
        $secondSummary = $service->runDailyPayroll($runDate);

        $this->assertSame(1, $firstSummary['paid']);
        $this->assertSame(0, $firstSummary['skipped_already_paid']);
        $this->assertSame(0, $secondSummary['paid']);
        $this->assertSame(1, $secondSummary['skipped_already_paid']);

        $this->assertSame(100.0, (float) $account->fresh()->money);
        $this->assertSame(1, Transaction::query()->where('transaction_type', 'payroll')->count());
        $this->assertDatabaseHas('transactions', [
            'payroll_member_id' => $member->id,
            'payroll_run_date' => '2026-06-05 00:00:00',
            'payroll_idempotency_key' => "payroll:{$member->id}:2026-06-05",
        ]);
    }

    /**
     * @return array{0: PayrollMember, 1: Account}
     */
    private function createPayrollFixture(): array
    {
        $nation = Nation::factory()->create();
        $grade = PayrollGrade::factory()->create([
            'weekly_amount' => 700,
            'is_enabled' => true,
        ]);
        $member = PayrollMember::factory()->create([
            'nation_id' => $nation->id,
            'payroll_grade_id' => $grade->id,
            'is_active' => true,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Payroll account';
        $account->money = 0;
        $account->save();

        return [$member, $account];
    }

    private function payrollService(): PayrollService
    {
        $membershipService = Mockery::mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('contains')->twice()->andReturnTrue();

        return new PayrollService(
            $membershipService,
            Mockery::mock(AuditLogger::class)
        );
    }
}
