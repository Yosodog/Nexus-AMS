<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\CityGrantNotification;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CityGrantWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setCityAverage(15.0);
        SettingService::setCityAverageUpdatedAt(now());
        SettingService::setGrantApprovalsEnabled(true);
    }

    public function test_member_can_request_a_city_grant_with_an_owned_account(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);

        $this->actingAs($user)
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('city_grant_requests', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'city_number' => $grant->city_number,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_member_cannot_request_a_duplicate_pending_city_grant(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);

        CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 250000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('grants.city'))
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'You are not eligible for this grant. You have a pending city grant.');

        $this->assertSame(1, CityGrantRequest::query()->count());
    }

    public function test_admin_can_approve_a_pending_city_grant_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();
        $account->refresh();

        $this->assertSame('approved', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->approved_at);
        $this->assertSame('320000.00', number_format((float) $account->money, 2, '.', ''));

        Notification::assertSentTo(
            $nation,
            CityGrantNotification::class,
            fn (CityGrantNotification $notification): bool => $notification->status === 'approved'
                && $notification->request->is($request)
        );
    }

    public function test_admin_can_deny_a_pending_city_grant_request(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.deny', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();

        $this->assertSame('denied', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->denied_at);

        Notification::assertSentTo(
            $nation,
            CityGrantNotification::class,
            fn (CityGrantNotification $notification): bool => $notification->status === 'denied'
                && $notification->request->is($request)
        );
    }

    public function test_admin_cannot_approve_their_own_city_grant_request(): void
    {
        [$admin, $nation, $account] = $this->createMemberWithAccount(777699, admin: true);
        $admin = $this->grantPermissions($admin, ['manage-city-grants']);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertForbidden();

        $request->refresh();

        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->pending_key);
    }

    public function test_member_cannot_request_a_city_grant_already_approved_for_that_city_number(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777602);
        $grant = $this->createCityGrant($nation->num_cities + 1);

        CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'pending_key' => null,
            'approved_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('grants.city'))
            ->post(route('grants.city.request'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', "You are not eligible for this grant. You've already gotten that city grant");
    }

    public function test_admin_cannot_approve_a_city_grant_while_global_approvals_are_disabled(): void
    {
        SettingService::setGrantApprovalsEnabled(false);

        [$user, $nation, $account] = $this->createMemberWithAccount(777603);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'Grant approvals are currently paused.');

        $request->refresh();
        $this->assertSame('pending', $request->status);
    }

    public function test_city_grant_approval_denies_when_account_ownership_does_not_match(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777604);
        [, , $foreignAccount] = $this->createMemberWithAccount(777605);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $foreignAccount->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'success');

        $request->refresh();
        $account->refresh();
        $foreignAccount->refresh();

        $this->assertSame('denied', $request->status);
        $this->assertNull($request->pending_key);
        $this->assertNotNull($request->denied_at);
        $this->assertSame(0.0, (float) $account->money);
        $this->assertSame(0.0, (float) $foreignAccount->money);
    }

    public function test_city_grant_approval_fails_when_the_grant_is_missing_or_disabled(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777606);
        $grant = $this->createCityGrant($nation->num_cities + 1);
        $request = CityGrantRequest::query()->create([
            'city_number' => $grant->city_number,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
        $grant->delete();
        $admin = $this->createAdminWithPermission('manage-city-grants');

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.grants.city.approve', ['CityGrantRequest' => $request->id]))
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'This city grant is currently disabled.');

        $request->refresh();
        $this->assertSame('pending', $request->status);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777601, bool $admin = false): array
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

    private function createCityGrant(int $cityNumber): CityGrant
    {
        return CityGrant::query()->create([
            'description' => 'Growth support',
            'enabled' => true,
            'grant_amount' => 100,
            'city_number' => $cityNumber,
            'requirements' => [],
        ]);
    }

    private function createAdminWithPermission(string $permission): User
    {
        [$admin] = $this->createMemberWithAccount(777699, admin: true);

        return $this->grantPermissions($admin, [$permission]);
    }
}
