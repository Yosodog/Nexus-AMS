<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\Nation;
use App\Models\RebuildingRequest;
use App\Models\RebuildingTier;
use App\Models\User;
use App\Notifications\RebuildingNotification;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RebuildingWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setRebuildingEnabled(true);
        SettingService::setRebuildingCycleId(3);
    }

    public function test_member_can_submit_a_rebuilding_request_with_an_owned_account(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $tier = $this->createTier();

        $this->actingAs($user)
            ->post(route('defense.rebuilding.store'), [
                'account_id' => $account->id,
                'note' => 'Need help after a war',
            ])
            ->assertRedirect(route('defense.rebuilding'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('rebuilding_requests', [
            'cycle_id' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $tier->id,
            'status' => 'pending',
            'pending_key' => 1,
            'note' => 'Need help after a war',
        ]);
    }

    public function test_member_cannot_submit_a_duplicate_pending_rebuilding_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $tier = $this->createTier();

        RebuildingRequest::query()->create([
            'cycle_id' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $tier->id,
            'city_count_snapshot' => 5,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 100000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('defense.rebuilding'))
            ->post(route('defense.rebuilding.store'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('defense.rebuilding'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'You already have a pending rebuilding request.');

        $this->assertSame(1, RebuildingRequest::query()->count());
    }

    public function test_admin_can_approve_a_pending_rebuilding_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $tier = $this->createTier();
        $request = RebuildingRequest::query()->create([
            'cycle_id' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $tier->id,
            'city_count_snapshot' => 5,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 180000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-rebuilding');

        $this->actingAs($admin)
            ->from(route('admin.rebuilding.index'))
            ->patch(route('admin.rebuilding.approve', ['RebuildingRequest' => $request->id]), [
                'approved_amount' => 225000,
                'review_note' => 'Validated losses',
            ])
            ->assertRedirect(route('admin.rebuilding.index'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();
        $account->refresh();

        $this->assertSame('approved', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertSame(225000.0, (float) $request->approved_amount);
        $this->assertSame('225000.00', number_format((float) $account->money, 2, '.', ''));
        $this->assertNotNull($request->approved_at);
        $this->assertSame($admin->id, $request->approved_by);

        Notification::assertSentTo(
            $nation,
            RebuildingNotification::class,
            fn (RebuildingNotification $notification): bool => $notification->toPNW($nation)['subject'] === 'Rebuilding Approved'
        );
    }

    public function test_admin_can_deny_a_pending_rebuilding_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $tier = $this->createTier();
        $request = RebuildingRequest::query()->create([
            'cycle_id' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $tier->id,
            'city_count_snapshot' => 5,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 180000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-rebuilding');

        $this->actingAs($admin)
            ->from(route('admin.rebuilding.index'))
            ->patch(route('admin.rebuilding.deny', ['RebuildingRequest' => $request->id]), [
                'review_note' => 'Not eligible this cycle',
            ])
            ->assertRedirect(route('admin.rebuilding.index'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();

        $this->assertSame('denied', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->denied_at);
        $this->assertSame($admin->id, $request->denied_by);

        Notification::assertSentTo(
            $nation,
            RebuildingNotification::class,
            fn (RebuildingNotification $notification): bool => $notification->toPNW($nation)['subject'] === 'Rebuilding Denied'
        );
    }

    public function test_admin_cannot_approve_their_own_rebuilding_request(): void
    {
        [$admin, $nation, $account] = $this->createMemberWithAccount(777599, admin: true);
        $admin = $this->grantPermissions($admin, ['manage-rebuilding']);
        $tier = $this->createTier();
        $request = RebuildingRequest::query()->create([
            'cycle_id' => 3,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $tier->id,
            'city_count_snapshot' => 5,
            'target_infrastructure_snapshot' => 1700,
            'estimated_amount' => 180000,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.rebuilding.approve', ['RebuildingRequest' => $request->id]), [
                'approved_amount' => 200000,
            ])
            ->assertForbidden();

        $request->refresh();

        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->pending_key);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777501, bool $admin = false): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
            'num_cities' => 5,
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

    private function createTier(): RebuildingTier
    {
        return RebuildingTier::query()->create([
            'name' => 'Standard',
            'min_city_count' => 1,
            'max_city_count' => 10,
            'target_infrastructure' => 1700,
            'is_active' => true,
            'requirements' => [],
        ]);
    }

    private function createAdminWithPermission(string $permission): User
    {
        [$admin] = $this->createMemberWithAccount(777599, admin: true);

        return $this->grantPermissions($admin, [$permission]);
    }
}
