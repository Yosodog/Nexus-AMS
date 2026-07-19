<?php

namespace Tests\Feature;

use App\Models\LotteryDrawing;
use App\Models\Setting;
use App\Models\User;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminLotterySettingsTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_lottery_admin_page_requires_a_lottery_permission(): void
    {
        $this->actingAs($this->createAdmin())
            ->get(route('admin.lottery.index'))
            ->assertForbidden();
    }

    public function test_view_only_staff_can_read_but_cannot_change_lottery_configuration(): void
    {
        $viewer = $this->createAdmin(['view-lottery'], 940002);

        $this->actingAs($viewer)
            ->get(route('admin.lottery.index'))
            ->assertOk()
            ->assertSee('View only')
            ->assertDontSee('Save lottery configuration');

        $this->actingAs($viewer)
            ->post(route('admin.lottery.settings.update'), $this->validPayload())
            ->assertForbidden();
    }

    public function test_manager_updates_live_guardrails_and_next_drawing_economics(): void
    {
        CarbonImmutable::setTestNow('2026-07-15 12:00:00 UTC');
        $manager = $this->createAdmin(['manage-lottery'], 940003);
        $currentDrawing = LotteryDrawing::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-07-12 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-19 00:00:00 UTC'),
            'sales_enabled' => true,
            'ticket_price' => 50000,
            'jackpot_basis_points' => 9000,
            'jackpot_contribution_per_ticket' => 45000,
            'max_tickets_per_purchase' => 100,
            'max_tickets_per_nation' => 10000,
        ]);

        $this->actingAs($manager)
            ->post(route('admin.lottery.settings.update'), $this->validPayload([
                'lottery_sales_enabled' => '0',
                'ticket_price' => '75000',
                'jackpot_percentage' => '85.00',
                'max_tickets_per_purchase' => '75',
                'max_tickets_per_nation' => '8000',
            ]))
            ->assertRedirect(route('admin.lottery.index'))
            ->assertSessionHas('alert-type', 'success');

        $settings = SettingService::getLotterySettings();
        $this->assertFalse($settings['sales_enabled']);
        $this->assertSame(7500000, $settings['ticket_price_cents']);
        $this->assertSame(8500, $settings['jackpot_basis_points']);
        $this->assertSame(75, $settings['max_tickets_per_purchase']);
        $this->assertSame(8000, $settings['max_tickets_per_nation']);

        $currentDrawing->refresh();
        $this->assertFalse($currentDrawing->sales_enabled);
        $this->assertSame(75, $currentDrawing->max_tickets_per_purchase);
        $this->assertSame(8000, $currentDrawing->max_tickets_per_nation);
        $this->assertSame('50000.00', $currentDrawing->ticket_price);
        $this->assertSame(9000, $currentDrawing->jackpot_basis_points);
        $this->assertSame('45000.00', $currentDrawing->jackpot_contribution_per_ticket);

        $nextDrawing = app(LotteryService::class)->currentDrawing(
            CarbonImmutable::parse('2026-07-19 12:00:00 UTC'),
        );
        $this->assertFalse($nextDrawing->sales_enabled);
        $this->assertSame('75000.00', $nextDrawing->ticket_price);
        $this->assertSame(8500, $nextDrawing->jackpot_basis_points);
        $this->assertSame('63750.00', $nextDrawing->jackpot_contribution_per_ticket);
        $this->assertSame(75, $nextDrawing->max_tickets_per_purchase);
        $this->assertSame(8000, $nextDrawing->max_tickets_per_nation);

        $this->assertDatabaseHas('audit_logs', [
            'category' => 'settings',
            'action' => 'lottery_configuration_updated',
            'actor_id' => $manager->id,
        ]);
    }

    public function test_lottery_configuration_rejects_unsafe_limits(): void
    {
        $manager = $this->createAdmin(['manage-lottery'], 940004);

        $this->actingAs($manager)
            ->post(route('admin.lottery.settings.update'), $this->validPayload([
                'ticket_price' => (string) ((SettingService::MAX_LOTTERY_TICKET_PRICE_CENTS / 100) + 1),
                'jackpot_percentage' => '100.001',
                'max_tickets_per_purchase' => '500',
                'max_tickets_per_nation' => '499',
            ]))
            ->assertSessionHasErrors([
                'ticket_price',
                'jackpot_percentage',
                'max_tickets_per_purchase',
            ]);

        $this->assertDatabaseMissing('settings', ['key' => 'lottery_configuration']);
    }

    public function test_ticket_price_ceiling_fits_a_full_drawing_plus_one_rollover_in_the_payout_ledger(): void
    {
        $maximumTwoDrawingPoolCents = SettingService::MAX_LOTTERY_TICKET_PRICE_CENTS
            * LotteryRandomizer::CODE_SPACE_SIZE
            * 2;

        $this->assertLessThanOrEqual(99_999_999_999_999, $maximumTwoDrawingPoolCents);
    }

    public function test_malformed_stored_lottery_configuration_falls_back_to_safe_bounds(): void
    {
        Setting::query()->create([
            'key' => 'lottery_configuration',
            'value' => '{not-json',
        ]);

        $this->assertSame([
            'sales_enabled' => true,
            'ticket_price_cents' => SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS,
            'jackpot_basis_points' => SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS,
            'max_tickets_per_purchase' => SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE,
            'max_tickets_per_nation' => SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION,
        ], SettingService::getLotterySettings());

        Setting::query()->where('key', 'lottery_configuration')->update([
            'value' => json_encode([
                'sales_enabled' => false,
                'ticket_price_cents' => -50,
                'jackpot_basis_points' => 50000,
                'max_tickets_per_purchase' => 9000,
                'max_tickets_per_nation' => 20000,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame([
            'sales_enabled' => false,
            'ticket_price_cents' => 100,
            'jackpot_basis_points' => 10000,
            'max_tickets_per_purchase' => SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE,
            'max_tickets_per_nation' => SettingService::MAX_LOTTERY_TICKETS_PER_NATION,
        ], SettingService::getLotterySettings());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'lottery_sales_enabled' => '1',
            'ticket_price' => '50000',
            'jackpot_percentage' => '90.00',
            'max_tickets_per_purchase' => '100',
            'max_tickets_per_nation' => '10000',
        ], $overrides);
    }

    /** @param array<int, string> $permissions */
    private function createAdmin(array $permissions = [], int $nationId = 940001): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => $nationId]);
        $this->attachDiscordAccount($admin, ['discord_id' => (string) ($nationId + 1000000)]);

        return $permissions === [] ? $admin : $this->grantPermissions($admin, $permissions);
    }
}
