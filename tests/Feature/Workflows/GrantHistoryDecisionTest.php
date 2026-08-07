<?php

namespace Tests\Feature\Workflows;

use App\Enums\GrantDecisionReason;
use App\Models\Account;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\GrantNotification;
use App\Services\AuthoritativeNationMembershipService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class GrantHistoryDecisionTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setGrantApprovalsEnabled(true);
        $this->app->instance(
            AuthoritativeNationMembershipService::class,
            $this->createStub(AuthoritativeNationMembershipService::class),
        );
    }

    public function test_application_snapshots_are_immutable_and_approval_uses_the_submitted_payout(): void
    {
        [$member, $nation, $account] = $this->createMemberWithAccount(770001);
        $grant = $this->createGrant([
            'name' => 'Original Growth Grant',
            'slug' => 'original-growth-grant',
            'version' => 4,
            'money' => 125000,
            'steel' => 75,
        ]);

        $this->actingAs($member)
            ->post(route('grants.apply', ['grant' => $grant->slug]), [
                'account_id' => $account->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $application = GrantApplication::query()->sole();

        $this->assertSame('Original Growth Grant', $application->program_name_snapshot);
        $this->assertSame(4, $application->program_version_snapshot);
        $this->assertSame(125000, (int) $application->money);
        $this->assertSame(75, (int) $application->steel);
        $this->assertNotNull($application->submitted_at);

        $grant->name = 'Renamed Growth Grant';
        $grant->slug = 'renamed-growth-grant';
        $grant->version = 5;
        $grant->money = 900000;
        $grant->steel = 900;
        $grant->save();

        $application->refresh();
        $this->assertSame('Original Growth Grant', $application->program_name_snapshot);
        $this->assertSame(4, $application->program_version_snapshot);
        $this->assertSame(125000, (int) $application->money);
        $this->assertSame(75, (int) $application->steel);

        $admin = $this->createAdminWithPermissions(['manage-grants'], 770099);

        $this->actingAs($admin)
            ->post(route('admin.grants.approve', ['application' => $application->id]))
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $application->refresh();
        $account->refresh();

        $this->assertSame('approved', $application->status);
        $this->assertSame(GrantDecisionReason::Approved, $application->decision_reason_code);
        $this->assertSame($admin->id, $application->reviewed_by_user_id);
        $this->assertNotNull($application->decided_at);
        $this->assertNotNull($application->disbursed_at);
        $this->assertSame(125000, (int) $account->money);
        $this->assertSame(75, (int) $account->steel);
    }

    public function test_denial_records_sanitized_member_reason_and_keeps_notification_in_parity(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(770002);
        $grant = $this->createGrant();
        $application = $this->createGrantApplication($grant, $nation, $account);
        $admin = $this->createAdminWithPermissions(['manage-grants'], 770098);

        $this->actingAs($admin)
            ->post(route('admin.grants.deny', ['application' => $application->id]), [
                'reason_code' => GrantDecisionReason::EligibilityNotMet->value,
                'decision_explanation' => "<strong>Raise your city count, then apply again.</strong>\r\n",
                'decision_internal_note' => '<script>review marker</script> Staff-only context.',
            ])
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $application->refresh();

        $this->assertSame('denied', $application->status);
        $this->assertSame(GrantDecisionReason::EligibilityNotMet, $application->decision_reason_code);
        $this->assertSame('Raise your city count, then apply again.', $application->decision_explanation);
        $this->assertSame('review marker Staff-only context.', $application->decision_internal_note);
        $this->assertSame($admin->id, $application->reviewed_by_user_id);
        $this->assertNotNull($application->decided_at);
        $this->assertArrayNotHasKey('decision_internal_note', $application->toArray());
        $this->assertArrayNotHasKey('reviewed_by_user_id', $application->toArray());

        Notification::assertSentTo(
            $nation,
            GrantNotification::class,
            function (GrantNotification $notification) use ($nation): bool {
                $payload = $notification->toPNW($nation);

                return $notification->status === 'denied'
                    && str_contains($payload['message'], 'Eligibility requirements not met')
                    && str_contains($payload['message'], 'Raise your city count, then apply again.')
                    && ! str_contains($payload['message'], 'Staff-only context');
            },
        );
    }

    public function test_recorded_snapshot_columns_reject_direct_updates(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(770008);
        $grant = $this->createGrant();
        $application = $this->createGrantApplication($grant, $nation, $account);

        $application->program_name_snapshot = 'Rewritten history';

        $this->expectException(LogicException::class);
        $application->save();
    }

    public function test_denial_requires_an_approved_safe_reason_and_constructive_explanation(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(770003);
        $grant = $this->createGrant();
        $application = $this->createGrantApplication($grant, $nation, $account);
        $admin = $this->createAdminWithPermissions(['manage-grants'], 770097);

        $this->actingAs($admin)
            ->from(route('admin.grants'))
            ->post(route('admin.grants.deny', ['application' => $application->id]))
            ->assertRedirect(route('admin.grants'))
            ->assertSessionHasErrors('reason_code');

        $this->actingAs($admin)
            ->from(route('admin.grants'))
            ->post(route('admin.grants.deny', ['application' => $application->id]), [
                'reason_code' => GrantDecisionReason::OtherPolicyReason->value,
            ])
            ->assertRedirect(route('admin.grants'))
            ->assertSessionHasErrors('decision_explanation');

        $this->actingAs($admin)
            ->from(route('admin.grants'))
            ->post(route('admin.grants.deny', ['application' => $application->id]), [
                'reason_code' => GrantDecisionReason::PolicyRequirementsNotMet->value,
                'decision_explanation' => 'Internal fraud investigation is still open.',
                'decision_internal_note' => 'Allowed only in the internal field.',
            ])
            ->assertRedirect(route('admin.grants'))
            ->assertSessionHasErrors('decision_explanation');

        $this->actingAs($admin)
            ->from(route('admin.grants'))
            ->post(route('admin.grants.deny', ['application' => $application->id]), [
                'reason_code' => GrantDecisionReason::EligibilityNotMet->value,
                'decision_explanation' => ['unexpected-array'],
            ])
            ->assertRedirect(route('admin.grants'))
            ->assertSessionHasErrors('decision_explanation');

        $this->actingAs($admin)
            ->from(route('admin.grants'))
            ->post(route('admin.grants.deny', ['application' => $application->id]), [
                'reason_code' => GrantDecisionReason::EligibilityNotMet->value,
                'decision_explanation' => str_repeat('a', 1001),
            ])
            ->assertRedirect(route('admin.grants'))
            ->assertSessionHasErrors('decision_explanation');

        $this->assertSame('pending', $application->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_member_history_is_actor_scoped_chronological_and_never_fabricates_legacy_program_data(): void
    {
        [$member, $nation, $account] = $this->createMemberWithAccount(770004);
        [, $otherNation, $otherAccount] = $this->createMemberWithAccount(770005);
        $grant = $this->createGrant([
            'name' => 'Current Renamed Program',
            'slug' => 'current-renamed-program',
        ]);

        $legacy = GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'denied',
            'pending_key' => null,
            'denied_at' => now()->subDays(5),
            'decision_internal_note' => 'Legacy staff secret',
        ]);
        GrantApplication::query()->whereKey($legacy->id)->update([
            'created_at' => now()->subDays(6),
            'updated_at' => now()->subDays(5),
        ]);

        GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'program_name_snapshot' => 'Captured Program B',
            'program_version_snapshot' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'pending_key' => null,
            'decision_reason_code' => GrantDecisionReason::Approved,
            'decision_internal_note' => 'Current staff secret',
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subHours(20),
            'decided_at' => now()->subHours(20),
            'disbursed_at' => now()->subHours(20),
            'money' => 250000,
        ]);

        GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'program_name_snapshot' => 'Other Member Program',
            'program_version_snapshot' => 2,
            'nation_id' => $otherNation->id,
            'account_id' => $otherAccount->id,
            'status' => 'denied',
            'pending_key' => null,
            'decision_reason_code' => GrantDecisionReason::EligibilityNotMet,
            'submitted_at' => now(),
            'denied_at' => now(),
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($member)
            ->get(route('grants.history'))
            ->assertOk()
            ->assertSee('Version 3')
            ->assertSee('Request #'.$legacy->id)
            ->assertDontSee('Other Member Program')
            ->assertDontSee('Legacy staff secret')
            ->assertDontSee('Current staff secret');

        $content = $response->getContent();
        $capturedPosition = strpos($content, 'Captured Program B');
        $legacyPosition = strpos($content, 'Request #'.$legacy->id);
        $legacyArticleStart = strrpos(substr($content, 0, $legacyPosition), '<article');
        $legacyArticleEnd = strpos($content, '</article>', $legacyPosition);
        $legacyArticle = substr($content, $legacyArticleStart, $legacyArticleEnd - $legacyArticleStart);

        $this->assertIsInt($capturedPosition);
        $this->assertIsInt($legacyPosition);
        $this->assertLessThan($legacyPosition, $capturedPosition);
        $this->assertStringContainsString('Not recorded', $legacyArticle);
        $this->assertStringNotContainsString('Current Renamed Program', $legacyArticle);
    }

    public function test_internal_notes_are_selected_and_rendered_only_for_managing_staff(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(770006);
        $grant = $this->createGrant();
        GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'program_name_snapshot' => 'Recorded Program',
            'program_version_snapshot' => 2,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'denied',
            'pending_key' => null,
            'decision_reason_code' => GrantDecisionReason::MoreInformationRequired,
            'decision_explanation' => 'Please attach the missing account details.',
            'decision_internal_note' => 'Internal reviewer context only.',
            'submitted_at' => now()->subDay(),
            'denied_at' => now(),
            'decided_at' => now(),
        ]);

        $viewer = $this->createAdminWithPermissions(['view-grants'], 770096);

        $this->actingAs($viewer)
            ->get(route('admin.grants'))
            ->assertOk()
            ->assertSee('Please attach the missing account details.')
            ->assertDontSee('Internal reviewer context only.');

        $manager = $this->createAdminWithPermissions(['view-grants', 'manage-grants'], 770095);

        $this->actingAs($manager)
            ->get(route('admin.grants'))
            ->assertOk()
            ->assertSee('Internal reviewer context only.');
    }

    public function test_grant_decisions_and_staff_history_require_grant_permissions(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(770007);
        $grant = $this->createGrant();
        $application = $this->createGrantApplication($grant, $nation, $account);
        [$unauthorizedAdmin] = $this->createMemberWithAccount(770094, admin: true);

        $this->actingAs($unauthorizedAdmin)
            ->get(route('admin.grants'))
            ->assertForbidden();

        $this->actingAs($unauthorizedAdmin)
            ->post(route('admin.grants.deny', ['application' => $application->id]), [
                'reason_code' => GrantDecisionReason::EligibilityNotMet->value,
            ])
            ->assertForbidden();

        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_editing_a_program_increments_its_version_for_future_snapshots(): void
    {
        $grant = $this->createGrant([
            'version' => 1,
            'money' => 100000,
        ]);
        $admin = $this->createAdminWithPermissions(['manage-grants'], 770093);

        $this->actingAs($admin)
            ->post(route('admin.grants.update', ['grant' => $grant->id]), [
                'name' => $grant->name,
                'description' => 'Updated program policy.',
                'money' => 150000,
                'is_enabled' => '1',
                'is_one_time' => '0',
                'validation_rules_json' => '',
            ])
            ->assertRedirect(route('admin.grants'));

        $grant->refresh();

        $this->assertSame(2, $grant->version);
        $this->assertSame(150000, (int) $grant->money);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId, bool $admin = false): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $userFactory = $admin
            ? User::factory()->verified()->admin()
            : User::factory()->verified();

        $user = $userFactory->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        if ($admin) {
            $this->attachDiscordAccount($user);
        }

        return [$user, $nation, $account];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions, int $nationId): User
    {
        [$admin] = $this->createMemberWithAccount($nationId, admin: true);

        return $this->grantPermissions($admin, $permissions);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGrant(array $overrides = []): Grants
    {
        $grant = new Grants;
        $grant->name = $overrides['name'] ?? 'Growth Grant';
        $grant->slug = $overrides['slug'] ?? 'growth-grant';
        $grant->version = $overrides['version'] ?? 1;
        $grant->description = $overrides['description'] ?? 'Support for growth.';
        $grant->money = $overrides['money'] ?? 100000;

        foreach (GrantApplication::PAYOUT_COLUMNS as $resource) {
            if ($resource !== 'money') {
                $grant->{$resource} = $overrides[$resource] ?? 0;
            }
        }

        $grant->validation_rules = $overrides['validation_rules'] ?? [];
        $grant->is_enabled = $overrides['is_enabled'] ?? true;
        $grant->is_one_time = $overrides['is_one_time'] ?? false;
        $grant->save();

        return $grant;
    }

    private function createGrantApplication(Grants $grant, Nation $nation, Account $account): GrantApplication
    {
        return GrantApplication::query()->create(array_merge([
            'grant_id' => $grant->id,
            'program_name_snapshot' => $grant->name,
            'program_version_snapshot' => $grant->version,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
            'submitted_at' => now(),
        ], collect(GrantApplication::PAYOUT_COLUMNS)
            ->mapWithKeys(fn (string $resource): array => [$resource => $grant->{$resource} ?? 0])
            ->all()));
    }
}
