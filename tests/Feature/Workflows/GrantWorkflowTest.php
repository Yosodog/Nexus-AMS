<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\GrantNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class GrantWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
    }

    public function test_member_can_submit_a_grant_application_with_an_owned_account(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createGrant();

        $this->actingAs($user)
            ->post(route('grants.apply', ['grant' => $grant->slug]), [
                'account_id' => $account->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('grant_applications', [
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_member_cannot_submit_a_duplicate_pending_grant_application(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createGrant();

        GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('grants.show_grants', ['grant' => $grant->slug]))
            ->post(route('grants.apply', ['grant' => $grant->slug]), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.show_grants', ['grant' => $grant->slug]))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'You already have a pending application for this grant.');

        $this->assertSame(1, GrantApplication::query()->count());
    }

    public function test_member_cannot_submit_a_grant_application_with_another_nations_account(): void
    {
        [$user] = $this->createMemberWithAccount();
        [, , $otherAccount] = $this->createMemberWithAccount(888);
        $grant = $this->createGrant();

        $this->actingAs($user)
            ->from(route('grants.show_grants', ['grant' => $grant->slug]))
            ->post(route('grants.apply', ['grant' => $grant->slug]), [
                'account_id' => $otherAccount->id,
            ])
            ->assertRedirect(route('grants.show_grants', ['grant' => $grant->slug]))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'You do not own the selected account.');

        $this->assertSame(0, GrantApplication::query()->count());
    }

    public function test_admin_can_approve_a_pending_grant_application(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createGrant([
            'money' => 250000,
        ]);
        $application = GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-grants');

        $this->actingAs($admin)
            ->post(route('admin.grants.approve', ['application' => $application->id]))
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $application->refresh();
        $account->refresh();

        $this->assertSame('approved', $application->status);
        $this->assertNull($application->pending_key);
        $this->assertNotNull($application->approved_at);
        $this->assertSame('250000.00', number_format((float) $account->money, 2, '.', ''));

        $this->assertDatabaseHas('manual_transactions', [
            'account_id' => $account->id,
            'admin_id' => $admin->id,
            'money' => 250000,
            'grant_application_id' => $application->id,
        ]);

        Notification::assertSentTo(
            $nation,
            GrantNotification::class,
            fn (GrantNotification $notification): bool => $notification->status === 'approved'
                && $notification->application->is($application)
        );
    }

    public function test_admin_cannot_approve_their_own_grant_application(): void
    {
        [$admin, $nation, $account] = $this->createMemberWithAccount(999, admin: true);
        $admin = $this->attachAdminPermission($admin, 'manage-grants');
        $grant = $this->createGrant();
        $application = GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.grants.approve', ['application' => $application->id]))
            ->assertForbidden();

        $application->refresh();

        $this->assertSame('pending', $application->status);
        $this->assertSame(1, $application->pending_key);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777001, bool $admin = false): array
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

    private function createAdminWithPermission(string $permission): User
    {
        [$admin] = $this->createMemberWithAccount(777099, admin: true);

        return $this->attachAdminPermission($admin, $permission);
    }

    private function attachAdminPermission(User $admin, string $permission): User
    {
        return $this->grantPermissions($admin, [$permission]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGrant(array $overrides = []): Grants
    {
        $grant = new Grants;
        $grant->name = $overrides['name'] ?? 'Growth Grant';
        $grant->slug = $overrides['slug'] ?? 'growth-grant';
        $grant->description = $overrides['description'] ?? 'Support for growth.';
        $grant->money = $overrides['money'] ?? 100000;
        $grant->coal = $overrides['coal'] ?? 0;
        $grant->oil = $overrides['oil'] ?? 0;
        $grant->uranium = $overrides['uranium'] ?? 0;
        $grant->iron = $overrides['iron'] ?? 0;
        $grant->bauxite = $overrides['bauxite'] ?? 0;
        $grant->lead = $overrides['lead'] ?? 0;
        $grant->gasoline = $overrides['gasoline'] ?? 0;
        $grant->munitions = $overrides['munitions'] ?? 0;
        $grant->steel = $overrides['steel'] ?? 0;
        $grant->aluminum = $overrides['aluminum'] ?? 0;
        $grant->food = $overrides['food'] ?? 0;
        $grant->validation_rules = $overrides['validation_rules'] ?? [];
        $grant->is_enabled = $overrides['is_enabled'] ?? true;
        $grant->is_one_time = $overrides['is_one_time'] ?? false;
        $grant->save();

        return $grant;
    }
}
