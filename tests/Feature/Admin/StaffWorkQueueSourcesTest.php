<?php

namespace Tests\Feature\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Enums\BlockadeReliefStatus;
use App\Enums\LoanStatus;
use App\Models\Account;
use App\Models\Application;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\BlockadeReliefRequest;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\Nation;
use App\Models\RebuildingRequest;
use App\Models\RebuildingTier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class StaffWorkQueueSourcesTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_mature_workflows_are_normalized_with_direct_domain_links(): void
    {
        config()->set('federation.enabled', false);
        config()->set('pending_requests.cache_key', 'testing.staff-work-queue.sources');
        config()->set('pending_requests.projection_cache_key', 'testing.staff-work-queue.sources.projection');
        Cache::forget('testing.staff-work-queue.sources');
        Cache::forget('testing.staff-work-queue.sources.projection');

        $requester = Nation::factory()->create([
            'leader_name' => 'Requester Leader',
            'nation_name' => 'Requester Nation',
        ]);
        $recipient = Nation::factory()->create([
            'leader_name' => 'Recipient Leader',
            'nation_name' => 'Recipient Nation',
        ]);
        $sourceAccount = $this->account($requester, 'Requester account');
        $destinationAccount = $this->account($recipient, 'Recipient account');
        $creator = User::factory()->verified()->create(['nation_id' => $requester->id]);

        $application = Application::query()->create([
            'nation_id' => $requester->id,
            'leader_name_snapshot' => $requester->leader_name,
            'discord_user_id' => 'discord-requester',
            'discord_username' => 'requester-user',
            'status' => ApplicationStatus::Pending,
        ]);
        $cityGrant = CityGrantRequest::query()->create([
            'city_number' => 22,
            'grant_amount' => 2_000_000,
            'nation_id' => $requester->id,
            'account_id' => $sourceAccount->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $grantProgram = new Grants;
        $grantProgram->name = 'Operational grant';
        $grantProgram->slug = 'operational-grant';
        $grantProgram->description = 'A test grant program.';
        $grantProgram->save();
        $grant = GrantApplication::query()->create([
            'grant_id' => $grantProgram->id,
            'program_name_snapshot' => 'Operational grant v1',
            'program_version_snapshot' => 1,
            'nation_id' => $requester->id,
            'account_id' => $sourceAccount->id,
            'status' => 'pending',
            'pending_key' => 1,
            'submitted_at' => now(),
        ]);
        $loan = Loan::query()->create([
            'nation_id' => $requester->id,
            'account_id' => $sourceAccount->id,
            'amount' => 1_500_000,
            'remaining_balance' => 1_500_000,
            'status' => LoanStatus::Pending,
            'pending_key' => 1,
        ]);
        $withdrawal = new Transaction;
        $withdrawal->from_account_id = $sourceAccount->id;
        $withdrawal->nation_id = $requester->id;
        $withdrawal->transaction_type = 'withdrawal';
        $withdrawal->money = 75_000;
        $withdrawal->is_pending = true;
        $withdrawal->requires_admin_approval = true;
        $withdrawal->save();
        $memberTransfer = MemberTransfer::query()->create([
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'from_nation_id' => $requester->id,
            'to_nation_id' => $recipient->id,
            'created_by' => $creator->id,
            'status' => MemberTransfer::STATUS_PENDING,
            'money' => 25_000,
        ]);
        $warAid = WarAidRequest::query()->create([
            'nation_id' => $requester->id,
            'account_id' => $sourceAccount->id,
            'note' => 'Defensive resupply',
            'money' => 900_000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $tier = RebuildingTier::query()->create([
            'name' => 'Standard rebuild',
            'min_city_count' => 1,
            'target_infrastructure' => 2000,
            'is_active' => true,
        ]);
        $rebuilding = RebuildingRequest::query()->create([
            'cycle_id' => 1,
            'nation_id' => $requester->id,
            'account_id' => $sourceAccount->id,
            'tier_id' => $tier->id,
            'city_count_snapshot' => 22,
            'target_infrastructure_snapshot' => 2000,
            'estimated_amount' => 3_000_000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $blockade = BlockadeReliefRequest::factory()->create([
            'requester_nation_id' => $requester->id,
            'blockading_nation_id' => $recipient->id,
            'claimed_by_nation_id' => $recipient->id,
            'status' => BlockadeReliefStatus::Claimed,
            'pending_key' => 1,
            'deadline_at' => now()->addHours(3),
            'claimed_at' => now(),
        ]);
        $auditRule = AuditRule::query()->create([
            'name' => 'Readiness remediation',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'definition' => ['field' => 'nation.score', 'operator' => 'greater_than', 'value' => 0],
            'enabled' => true,
        ]);
        $audit = AuditResult::query()->create([
            'audit_rule_id' => $auditRule->id,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$requester->id,
            'nation_id' => $requester->id,
            'first_detected_at' => now()->subDays(2),
            'last_evaluated_at' => now(),
            'due_at' => now()->subHour(),
        ]);

        $projectionQueries = 0;
        DB::listen(static function () use (&$projectionQueries): void {
            $projectionQueries++;
        });

        $snapshot = app(StaffWorkQueueRegistry::class)->snapshot(forceRefresh: true);
        $items = collect($snapshot['items'])->keyBy('key');
        $expectedKeys = [
            'applications:'.$application->id,
            'city_grants:'.$cityGrant->id,
            'grants:'.$grant->id,
            'loans:'.$loan->id,
            'withdrawals:'.$withdrawal->id,
            'member_transfers:'.$memberTransfer->id,
            'war_aid:'.$warAid->id,
            'rebuilding:'.$rebuilding->id,
            'blockade_relief:'.$blockade->id,
            'audit_remediation:'.$audit->id,
        ];

        $this->assertTrue($snapshot['complete'], json_encode($snapshot['failures'], JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(35, $projectionQueries);
        $this->assertEqualsCanonicalizing($expectedKeys, $items->keys()->all());
        $this->assertSame(route('admin.applications.show', $application), $items['applications:'.$application->id]['url']);
        $this->assertSame(route('admin.loans.view', ['Loan' => $loan->id]), $items['loans:'.$loan->id]['url']);
        $this->assertStringStartsWith(route('admin.accounts.view', $sourceAccount), $items['withdrawals:'.$withdrawal->id]['url']);
        $this->assertSame(route('admin.member-transfers.show', $memberTransfer), $items['member_transfers:'.$memberTransfer->id]['url']);
        $this->assertStringStartsWith(route('admin.grants.city'), $items['city_grants:'.$cityGrant->id]['url']);
        $this->assertStringStartsWith(route('admin.grants'), $items['grants:'.$grant->id]['url']);
        $this->assertStringContainsString('Operational grant v1', $items['grants:'.$grant->id]['subject']);
        $this->assertStringStartsWith(route('admin.war-aid'), $items['war_aid:'.$warAid->id]['url']);
        $this->assertStringStartsWith(route('admin.rebuilding.index'), $items['rebuilding:'.$rebuilding->id]['url']);
        $this->assertStringStartsWith(route('defense.blockade-relief'), $items['blockade_relief:'.$blockade->id]['url']);
        $this->assertStringStartsWith(
            route('admin.audits.rules.violations', $auditRule),
            $items['audit_remediation:'.$audit->id]['url'],
        );
        $this->assertSame('nation:'.$recipient->id, $items['member_transfers:'.$memberTransfer->id]['owner_key']);
        $this->assertSame('nation:'.$recipient->id, $items['blockade_relief:'.$blockade->id]['owner_key']);

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey('subject', $items[$key]);
            $this->assertArrayHasKey('created_at', $items[$key]);
            $this->assertArrayHasKey('status_label', $items[$key]);
            $this->assertArrayHasKey('next_action_label', $items[$key]);
        }

        $viewer = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($viewer);
        $this->actingAs($viewer)
            ->get(route('admin.member-transfers.show', $memberTransfer))
            ->assertForbidden();

        $manager = $this->grantPermissions($this->createVerifiedAdmin(), ['manage-accounts']);
        $this->attachDiscordAccount($manager);
        $this->actingAs($manager)
            ->get(route('admin.member-transfers.show', $memberTransfer))
            ->assertOk()
            ->assertSee('Requester Leader')
            ->assertSee('Recipient Leader')
            ->assertSee('Cancel and refund');
    }

    private function account(Nation $nation, string $name): Account
    {
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = $name;
        $account->save();

        return $account;
    }
}
