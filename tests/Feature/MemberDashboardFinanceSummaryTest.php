<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\Nation;
use App\Services\MemberFinanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MemberDashboardFinanceSummaryTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_dashboard_uses_authoritative_state_filtered_finance_totals(): void
    {
        [$nation, $account] = $this->createMemberContext(910001);
        [$otherNation, $otherAccount] = $this->createNationContext();
        $grant = $this->createGrant();

        $this->createGrantApplication($grant, $nation, $account, 'approved', 1200);
        $this->createGrantApplication($grant, $nation, $account, 'pending', 9000);
        $this->createGrantApplication($grant, $nation, $account, 'denied', 8000);
        $this->createGrantApplication($grant, $otherNation, $otherAccount, 'approved', 50000);

        $this->createCityGrantRequest($nation, $account, 'approved', 3800);
        $this->createCityGrantRequest($nation, $account, 'pending', 7000);
        $this->createCityGrantRequest($nation, $account, 'denied', 6000);
        $this->createCityGrantRequest($otherNation, $otherAccount, 'approved', 50000);

        $this->createLoan($nation, $account, 'approved', 2500.25);
        $this->createLoan($nation, $account, 'missed', 1800.75);
        $this->createLoan($nation, $account, 'paid', 700);
        $this->createLoan($nation, $account, 'pending', 6000);
        $this->createLoan($nation, $account, 'denied', 5000);
        $this->createLoan($otherNation, $otherAccount, 'approved', 50000);

        $summary = app(MemberFinanceSummaryService::class)->forNation($nation);

        $this->assertSame(5000.0, $summary['grantTotal']);
        $this->assertSame(4301.0, $summary['loanTotal']);

        $response = $this->actingAs($nation->user)->get(route('user.dashboard'));

        $response
            ->assertOk()
            ->assertSeeInOrder([
                'Lifetime cash grants received',
                '$5,000.00',
                'Approved custom and city cash grants; pending and denied requests excluded',
                'Outstanding loan principal',
                '$4,301.00',
                'Remaining principal on approved and missed loans; pending, denied, and paid records excluded',
            ]);
    }

    public function test_dashboard_renders_a_real_zero_for_a_nation_without_finance_records(): void
    {
        [$nation] = $this->createMemberContext(910002);

        $summary = app(MemberFinanceSummaryService::class)->forNation($nation);

        $this->assertSame(0.0, $summary['grantTotal']);
        $this->assertSame(0.0, $summary['loanTotal']);

        $this->actingAs($nation->user)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Lifetime cash grants received',
                '$0.00',
                'Outstanding loan principal',
                '$0.00',
            ]);
    }

    public function test_finance_summary_keeps_loans_available_when_the_grant_source_cannot_be_queried(): void
    {
        [$nation, $account] = $this->createMemberContext(910003);
        $this->createLoan($nation, $account, 'approved', 1500);

        Schema::drop('grant_applications');

        $summary = app(MemberFinanceSummaryService::class)->forNation($nation);

        $this->assertNull($summary['grantTotal']);
        $this->assertSame(1500.0, $summary['loanTotal']);
    }

    public function test_dashboard_renders_an_unavailable_metric_without_turning_it_into_zero(): void
    {
        [$nation] = $this->createMemberContext(910004);

        $this->mock(MemberFinanceSummaryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forNation')
                ->once()
                ->andReturn([
                    'grantTotal' => null,
                    'loanTotal' => 1500.0,
                ]);
        });

        $this->actingAs($nation->user)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Lifetime cash grants received',
                'Unavailable',
                'Grant records could not be loaded',
                'Outstanding loan principal',
                '$1,500.00',
            ]);
    }

    /**
     * @return array{Nation, Account}
     */
    private function createMemberContext(int $nationId): array
    {
        [$nation, $account] = $this->createNationContext($nationId);
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);
        $this->attachDiscordAccount($user);
        $this->enableTwoFactor($user);

        $nation->setRelation('user', $user);

        return [$nation, $account];
    }

    /**
     * @return array{Nation, Account}
     */
    private function createNationContext(?int $nationId = null): array
    {
        $nation = Nation::factory()->create($nationId === null ? [] : ['id' => $nationId]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Main account';
        $account->save();

        return [$nation, $account];
    }

    private function createGrant(): Grants
    {
        $grant = new Grants;
        $grant->name = 'Dashboard grant';
        $grant->slug = 'dashboard-grant';
        $grant->description = 'Dashboard test grant';
        $grant->validation_rules = [];
        $grant->is_enabled = true;
        $grant->is_one_time = false;
        $grant->save();

        return $grant;
    }

    private function createGrantApplication(
        Grants $grant,
        Nation $nation,
        Account $account,
        string $status,
        int $money
    ): GrantApplication {
        return GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => $status,
            'pending_key' => $status === 'pending' ? 1 : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'denied_at' => $status === 'denied' ? now() : null,
            'money' => $money,
        ]);
    }

    private function createCityGrantRequest(
        Nation $nation,
        Account $account,
        string $status,
        int $grantAmount
    ): CityGrantRequest {
        return CityGrantRequest::query()->create([
            'city_number' => 10,
            'grant_amount' => $grantAmount,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => $status,
            'pending_key' => $status === 'pending' ? 1 : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'denied_at' => $status === 'denied' ? now() : null,
        ]);
    }

    private function createLoan(Nation $nation, Account $account, string $status, float $remainingBalance): Loan
    {
        $requiresSqliteStatusCompatibility = DB::getDriverName() === 'sqlite'
            && in_array($status, ['missed', 'paid'], true);

        if ($requiresSqliteStatusCompatibility) {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }

        try {
            return Loan::query()->create([
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'amount' => max($remainingBalance, 1000),
                'remaining_balance' => $remainingBalance,
                'interest_rate' => 1,
                'term_weeks' => 10,
                'status' => $status,
                'pending_key' => $status === 'pending' ? 1 : null,
                'approved_at' => in_array($status, ['approved', 'missed', 'paid'], true) ? now() : null,
            ]);
        } finally {
            if ($requiresSqliteStatusCompatibility) {
                DB::statement('PRAGMA ignore_check_constraints = OFF');
            }
        }
    }
}
