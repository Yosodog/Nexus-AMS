<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\Nation;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Notifications\WarAidNotification;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class WarAidWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setWarAidEnabled(true);
    }

    public function test_member_can_submit_a_war_aid_request_with_an_owned_account(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();

        $this->actingAs($user)
            ->post(route('defense.war-aid.store'), [
                'account_id' => $account->id,
                'note' => 'Frontline support',
                'money' => 125000,
                'food' => 450,
            ])
            ->assertRedirect(route('defense.war-aid'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('war_aid_requests', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Frontline support',
            'money' => 125000,
            'food' => 450,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_member_cannot_submit_a_duplicate_pending_war_aid_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();

        WarAidRequest::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Existing request',
            'money' => 1000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('defense.war-aid'))
            ->post(route('defense.war-aid.store'), [
                'account_id' => $account->id,
                'note' => 'Duplicate request',
                'money' => 5000,
            ])
            ->assertRedirect(route('defense.war-aid'))
            ->assertSessionHasErrors([
                'pending' => 'You already have a pending war aid request.',
            ]);

        $this->assertSame(1, WarAidRequest::query()->count());
    }

    public function test_admin_can_approve_a_pending_war_aid_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $request = WarAidRequest::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Support needed',
            'money' => 1000,
            'food' => 10,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-war-aid');

        $this->actingAs($admin)
            ->from(route('admin.war-aid'))
            ->patch(route('admin.war-aid.approve', ['WarAidRequest' => $request->id]), [
                'money' => 250000,
                'food' => 500,
            ])
            ->assertRedirect(route('admin.war-aid'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();
        $account->refresh();

        $this->assertSame('approved', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->approved_at);
        $this->assertSame('250000.00', number_format((float) $account->money, 2, '.', ''));
        $this->assertSame('500.00', number_format((float) $account->food, 2, '.', ''));

        Notification::assertSentTo(
            $nation,
            WarAidNotification::class,
            fn (WarAidNotification $notification): bool => $notification->status === 'approved'
                && $notification->request->is($request)
        );
    }

    public function test_admin_can_deny_a_pending_war_aid_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $request = WarAidRequest::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Support needed',
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-war-aid');

        $this->actingAs($admin)
            ->from(route('admin.war-aid'))
            ->patch(route('admin.war-aid.deny', ['WarAidRequest' => $request->id]))
            ->assertRedirect(route('admin.war-aid'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();

        $this->assertSame('denied', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->denied_at);

        Notification::assertSentTo(
            $nation,
            WarAidNotification::class,
            fn (WarAidNotification $notification): bool => $notification->status === 'denied'
                && $notification->request->is($request)
        );
    }

    public function test_admin_cannot_approve_their_own_war_aid_request(): void
    {
        [$admin, $nation, $account] = $this->createMemberWithAccount(777099, admin: true);
        $admin = $this->grantPermissions($admin, ['manage-war-aid']);
        $request = WarAidRequest::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Self review',
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.war-aid.approve', ['WarAidRequest' => $request->id]), [
                'money' => 50000,
            ])
            ->assertForbidden();

        $request->refresh();

        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->pending_key);
    }

    public function test_disabled_war_aid_setting_blocks_submission(): void
    {
        SettingService::setWarAidEnabled(false);
        [$user, , $account] = $this->createMemberWithAccount(777402);

        $this->actingAs($user)
            ->post(route('defense.war-aid.store'), [
                'account_id' => $account->id,
                'note' => 'Frontline support',
                'money' => 125000,
            ])
            ->assertRedirect(route('defense.war-aid'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'War aid is currently disabled.');
    }

    public function test_approving_a_non_pending_war_aid_request_raises_a_validation_error(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(777403);
        $request = WarAidRequest::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Already processed',
            'status' => 'approved',
            'pending_key' => null,
            'approved_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending war aid requests can be approved.');

        app(WarAidService::class)->approveAidRequest($request, ['money' => 10]);
    }

    public function test_denying_a_non_pending_war_aid_request_raises_a_validation_error(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount(777404);
        $request = WarAidRequest::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'note' => 'Already processed',
            'status' => 'denied',
            'pending_key' => null,
            'denied_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending war aid requests can be denied.');

        app(WarAidService::class)->denyAidRequest($request);
    }

    public function test_foreign_account_submission_is_rejected_cleanly(): void
    {
        [$user] = $this->createMemberWithAccount(777405);
        [, , $otherAccount] = $this->createMemberWithAccount(777406);

        $this->actingAs($user)
            ->from(route('defense.war-aid'))
            ->post(route('defense.war-aid.store'), [
                'account_id' => $otherAccount->id,
                'note' => 'Foreign account request',
                'money' => 1000,
            ])
            ->assertRedirect(route('defense.war-aid'))
            ->assertSessionHasErrors([
                'account_id' => 'You do not own the selected account.',
            ]);
    }

    public function test_resource_payload_persists_multiple_resource_types(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777407);

        $this->actingAs($user)
            ->post(route('defense.war-aid.store'), [
                'account_id' => $account->id,
                'note' => 'Mixed support',
                'money' => 125000,
                'coal' => 10,
                'aluminum' => 25,
                'food' => 450,
            ])
            ->assertRedirect(route('defense.war-aid'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('war_aid_requests', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'money' => 125000,
            'coal' => 10,
            'aluminum' => 25,
            'food' => 450,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777401, bool $admin = false): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $user = ($admin ? User::factory()->verified()->admin() : User::factory()->verified())->create([
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
        [$admin] = $this->createMemberWithAccount(777499, admin: true);

        return $this->grantPermissions($admin, [$permission]);
    }
}
