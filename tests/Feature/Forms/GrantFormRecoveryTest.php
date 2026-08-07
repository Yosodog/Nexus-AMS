<?php

namespace Tests\Feature\Forms;

use App\Models\Account;
use App\Models\Grants;
use App\Models\Nation;
use App\Models\User;
use App\Services\AuthoritativeNationMembershipService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GrantFormRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        SettingService::setGrantApprovalsEnabled(true);
        $this->app->instance(
            AuthoritativeNationMembershipService::class,
            $this->createStub(AuthoritativeNationMembershipService::class),
        );
    }

    public function test_server_validation_preserves_input_and_renders_linked_recovery_state(): void
    {
        [$user] = $this->createMemberWithAccount();
        $grant = $this->createGrant();
        $showUrl = route('grants.show_grants', ['grant' => $grant->slug]);

        $this->actingAs($user)
            ->from($showUrl)
            ->post(route('grants.apply', ['grant' => $grant->slug]), [
                'account_id' => '',
            ])
            ->assertRedirect($showUrl)
            ->assertInvalid(['account_id'])
            ->assertSessionHasInput('account_id', '');

        $this->get($showUrl)
            ->assertOk()
            ->assertSee('id="grant-application-errors"', false)
            ->assertSee('href="#grant-account"', false)
            ->assertSee('id="grant-account"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-errormessage="grant-account-error"', false)
            ->assertSee('Select an account for the grant disbursement.');
    }

    /**
     * @return array{User, Nation, Account}
     */
    private function createMemberWithAccount(): array
    {
        $nation = Nation::factory()->create([
            'id' => 777001,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);
        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        return [$user, $nation, $account];
    }

    private function createGrant(): Grants
    {
        $grant = new Grants;
        $grant->name = 'Growth Grant';
        $grant->slug = 'growth-grant';
        $grant->description = 'Support for growth.';
        $grant->money = 100000;
        $grant->validation_rules = [];
        $grant->is_enabled = true;
        $grant->is_one_time = false;
        $grant->save();

        return $grant;
    }
}
