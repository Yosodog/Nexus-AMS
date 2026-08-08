<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MMRConfig;
use App\Models\MMRSetting;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MMRAssistantAutoCoverageSettingsTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_member_can_enable_automatic_deficit_coverage_without_losing_manual_values(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();

        $this->actingAs($user)
            ->from(route('accounts'))
            ->post(route('mmra.update'), [
                'enabled' => '1',
                'auto_cover_resource_deficits' => '1',
                'account_id' => $account->id,
                'coal_pct' => '25',
                'oil_pct' => '15',
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('alert-type', 'success');

        $config = MMRConfig::query()->where('nation_id', $nation->id)->firstOrFail();

        $this->assertTrue($config->enabled);
        $this->assertTrue($config->auto_cover_resource_deficits);
        $this->assertSame(25.0, $config->coal_pct);
        $this->assertSame(15.0, $config->oil_pct);
    }

    public function test_switching_back_to_manual_mode_preserves_omitted_manual_values(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        MMRConfig::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'enabled' => true,
            'auto_cover_resource_deficits' => true,
            'coal_pct' => 30,
            'oil_pct' => 20,
        ]);

        $this->actingAs($user)
            ->post(route('mmra.update'), [
                'enabled' => '1',
                'auto_cover_resource_deficits' => '0',
                'account_id' => $account->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $config = MMRConfig::query()->where('nation_id', $nation->id)->firstOrFail();

        $this->assertFalse($config->auto_cover_resource_deficits);
        $this->assertSame(30.0, $config->coal_pct);
        $this->assertSame(20.0, $config->oil_pct);
    }

    public function test_manual_values_over_one_hundred_percent_are_rejected_in_automatic_mode(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();

        $this->actingAs($user)
            ->from(route('accounts'))
            ->post(route('mmra.update'), [
                'enabled' => '1',
                'auto_cover_resource_deficits' => '1',
                'account_id' => $account->id,
                'coal_pct' => '60',
                'oil_pct' => '50',
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors('allocation_total');

        $this->assertDatabaseMissing('mmr_configs', [
            'nation_id' => $nation->id,
        ]);
    }

    public function test_partial_update_cannot_push_preserved_manual_values_over_one_hundred_percent(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        MMRConfig::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'enabled' => true,
            'coal_pct' => 100,
        ]);

        $this->actingAs($user)
            ->from(route('accounts'))
            ->post(route('mmra.update'), [
                'enabled' => '1',
                'account_id' => $account->id,
                'oil_pct' => '100',
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors('allocation_total');

        $config = MMRConfig::query()->where('nation_id', $nation->id)->firstOrFail();

        $this->assertSame(100.0, $config->coal_pct);
        $this->assertSame(0.0, $config->oil_pct);
    }

    public function test_member_cannot_select_another_nations_account(): void
    {
        [$user, $nation] = $this->createMemberWithAccount();
        $otherNation = Nation::factory()->create();
        $otherAccount = $this->createAccount($otherNation, 'Other Nation');

        $this->actingAs($user)
            ->from(route('accounts'))
            ->post(route('mmra.update'), [
                'enabled' => '1',
                'auto_cover_resource_deficits' => '1',
                'account_id' => $otherAccount->id,
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors('account_id');

        $this->assertDatabaseMissing('mmr_configs', [
            'nation_id' => $nation->id,
        ]);
    }

    public function test_enabled_automatic_mode_renders_projection_and_preserves_manual_inputs(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount();
        $config = MMRConfig::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'enabled' => true,
            'auto_cover_resource_deficits' => true,
            'coal_pct' => 25,
        ]);
        $setting = new MMRSetting([
            'resource' => 'coal',
            'enabled' => true,
            'surcharge_pct' => 5,
        ]);

        $this->view('accounts.components.mmr_assistant', [
            'accounts' => collect([$account]),
            'mmrConfig' => $config,
            'mmrSettings' => collect(['coal' => $setting]),
            'mmrResources' => ['coal'],
            'mmrEnabled' => true,
            'mmrLogs' => new Paginator([], 10),
            'mmrAfterTaxIncome' => 1000,
            'mmrPrices' => ['coal' => 10],
            'mmrAutoPlan' => [
                'status' => 'available',
                'total_spend' => 320,
                'target_spend' => 320,
                'coverage_pct' => 100,
                'projection_calculated_at' => now()->subHour(),
                'unavailable_resources' => [],
                'lines' => [
                    'coal' => [
                        'qty' => 32,
                        'target_qty' => 32,
                        'spend' => 320,
                        'ppu' => 10,
                    ],
                ],
            ],
        ])
            ->assertSee('Automatically cover projected resource deficits')
            ->assertSee(config('app.name').' will buy up to one turn')
            ->assertSee('Purchasable coverage')
            ->assertSee('100.00%')
            ->assertSee('Estimated purchases this turn')
            ->assertSee('Estimated quantity')
            ->assertSee('of 32.00 needed')
            ->assertSee('at $10.00 each')
            ->assertSee('readonly', escape: false);
    }

    /**
     * @return array{User, Nation, Account}
     */
    private function createMemberWithAccount(): array
    {
        $nation = Nation::factory()->create();
        $user = $this->createVerifiedUser(['nation_id' => $nation->id]);
        $this->attachDiscordAccount($user);
        $account = $this->createAccount($nation, 'Member Account');

        return [$user, $nation, $account];
    }

    private function createAccount(Nation $nation, string $name): Account
    {
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = $name;
        $account->save();

        return $account;
    }
}
