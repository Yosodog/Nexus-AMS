<?php

namespace Tests\Feature;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Enums\LoanStatus;
use App\Models\Account;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\CityGrantRequest;
use App\Models\Loan;
use App\Models\Nation;
use App\Services\MemberDashboardAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MemberDashboardAttentionTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_current_compliant_member_has_no_attention_items(): void
    {
        $nation = Nation::factory()->create();

        $result = app(MemberDashboardAttentionService::class)->forNation($nation, [
            'latestSignIn' => (object) ['created_at' => now()],
            'mmrResourcesMet' => true,
            'mmrUnitsMet' => true,
        ]);

        $this->assertSame(0, $result['attentionCount']);
        $this->assertSame([], $result['attentionItems']);
    }

    public function test_attention_items_are_role_appropriate_and_ordered_by_urgency(): void
    {
        [$nation, $account] = $this->createNationContext();
        $this->createMissedLoan($nation, $account);
        $this->createAuditFinding($nation);
        $this->createPendingCityGrant($nation, $account);

        $result = app(MemberDashboardAttentionService::class)->forNation($nation, [
            'latestSignIn' => (object) ['created_at' => now()],
            'mmrResourcesMet' => false,
            'mmrUnitsMet' => true,
        ]);

        $this->assertSame(4, $result['attentionCount']);
        $this->assertSame([
            'overdue-loans',
            'audit-remediation',
            'readiness-requirements',
            'pending-requests',
        ], array_column($result['attentionItems'], 'id'));
        $this->assertSame('failure', $result['attentionItems'][0]['intent']);
        $this->assertStringContainsString('$140.00', $result['attentionItems'][0]['description']);
        $this->assertStringContainsString('city grant', $result['attentionItems'][3]['description']);
    }

    public function test_stale_snapshot_is_clearer_than_a_false_readiness_failure(): void
    {
        $nation = Nation::factory()->create();

        $result = app(MemberDashboardAttentionService::class)->forNation($nation, [
            'latestSignIn' => (object) ['created_at' => now()->subHours(37)],
            'mmrResourcesMet' => false,
            'mmrUnitsMet' => false,
        ]);

        $this->assertSame('readiness-stale', $result['attentionItems'][0]['id']);
        $this->assertSame('Data stale', $result['attentionItems'][0]['label']);
        $this->assertTrue($result['attentionItems'][0]['external']);
    }

    public function test_dashboard_renders_attention_before_readiness_and_preserves_stats(): void
    {
        [$nation, $account] = $this->createNationContext();
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);
        $this->attachDiscordAccount($user);
        $this->enableTwoFactor($user);
        $this->createMissedLoan($nation, $account);

        $this->actingAs($user)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'What needs my attention?',
                'A loan payment is overdue',
                'Military readiness',
                'Nation record',
            ]);
    }

    /**
     * @return array{Nation, Account}
     */
    private function createNationContext(): array
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Main account';
        $account->save();

        return [$nation, $account];
    }

    private function createMissedLoan(Nation $nation, Account $account): Loan
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }

        try {
            return Loan::query()->create([
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'amount' => 1000,
                'remaining_balance' => 900,
                'scheduled_weekly_payment' => 100,
                'past_due_amount' => 125,
                'accrued_interest_due' => 15,
                'interest_rate' => 2,
                'term_weeks' => 10,
                'status' => LoanStatus::Missed,
                'next_due_date' => now()->subDay(),
            ]);
        } finally {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA ignore_check_constraints = OFF');
            }
        }
    }

    private function createAuditFinding(Nation $nation): AuditResult
    {
        $rule = AuditRule::query()->create([
            'name' => 'Readiness check',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'definition' => ['field' => 'nation.score', 'operator' => 'greater_than', 'value' => 0],
            'enabled' => true,
        ]);

        return AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$nation->id,
            'nation_id' => $nation->id,
            'first_detected_at' => now()->subDays(3),
            'last_evaluated_at' => now(),
            'due_at' => now()->subDay(),
        ]);
    }

    private function createPendingCityGrant(Nation $nation, Account $account): CityGrantRequest
    {
        return CityGrantRequest::query()->create([
            'city_number' => 20,
            'grant_amount' => 1000000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }
}
