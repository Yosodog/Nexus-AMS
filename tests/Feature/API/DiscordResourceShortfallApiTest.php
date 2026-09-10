<?php

namespace Tests\Feature\API;

use App\Jobs\SendBank;
use App\Models\Account;
use App\Models\AlertOccurrence;
use App\Models\DiscordAccount;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRSetting;
use App\Models\Nation;
use App\Models\NationProfitabilitySnapshot;
use App\Models\NationResources;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawLimit;
use App\Services\Alerts\ResourceShortfallService;
use App\Services\Economy\EconomyRules;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\TradePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordResourceShortfallApiTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_USER_ID = '234567890123456789';

    private User $actor;

    private Nation $nation;

    private AlertOccurrence $occurrence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();
        config([
            'services.discord_bot_key' => 'test-discord-bot-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'services.discord.resource_shortfall_intent_ttl_seconds' => 900,
            'services.pw.api_key' => 'test-api-key',
        ]);
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'wars' => [
                        'data' => [],
                        'paginatorInfo' => ['perPage' => 1000, 'count' => 0, 'lastPage' => 1],
                    ],
                ],
            ]),
        ]);

        $this->nation = Nation::factory()->create();
        $this->actor = User::factory()->verified()->create(['nation_id' => $this->nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_USER_ID,
            'unlinked_at' => null,
        ]);
        $this->createNationResources();
        $this->createProjection();
        $this->occurrence = AlertOccurrence::factory()->create([
            'event_key' => ResourceShortfallService::EVENT_KEY,
            'audience_user_id' => $this->actor->id,
            'subject_type' => 'nation',
            'subject_id' => (string) $this->nation->id,
            'sensitivity' => 'restricted',
            'payload' => ['shortfalls' => []],
            'stale_at' => now()->addDay(),
        ]);
    }

    #[Test]
    public function member_can_fund_the_full_twelve_turn_withdrawal_from_one_account(): void
    {
        $account = $this->account(['coal' => 200]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/discord/me/resource-shortfall-alerts/{$this->occurrence->id}/options")
            ->assertOk()
            ->assertJsonPath('data.accounts.0.account_id', $account->id)
            ->assertJsonPath('data.accounts.0.mode', 'withdrawal')
            ->assertJsonPath('data.accounts.0.withdrawal_resources.coal', '120.00')
            ->assertJsonMissingPath('data.accounts.0.account_fingerprint');

        $draft = $this->withHeaders($this->headers('345678901234567891'))
            ->postJson("/api/v1/discord/me/resource-shortfall-alerts/{$this->occurrence->id}/drafts", [
                'account_id' => $account->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fulfillment.purchase_is_final', true);

        $this->withHeaders($this->headers('345678901234567892'))
            ->postJson('/api/v1/discord/me/resource-shortfall-fulfillments/'
                .$draft->json('data.fulfillment.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'dispatched')
            ->assertJsonPath('data.transaction.resources.coal', '120.00')
            ->assertJsonPath('data.purchase', null);

        $this->assertSame('80.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertDatabaseCount('transactions', 1);
        Queue::assertPushed(SendBank::class, 1);
    }

    #[Test]
    public function globally_enabled_mmr_sells_only_the_missing_amount_then_withdraws_it(): void
    {
        SettingService::setMMRAssistantEnabled(true);
        MMRSetting::query()->create([
            'resource' => 'coal',
            'enabled' => true,
            'surcharge_pct' => 0,
        ]);
        $priceService = $this->createMock(TradePriceService::class);
        $priceService->method('get24hAverageWithSurcharge')->willReturn(['coal' => 2.00]);
        $this->app->instance(TradePriceService::class, $priceService);
        $account = $this->account(['money' => 1000, 'coal' => 20]);

        $draft = $this->withHeaders($this->headers('345678901234567893'))
            ->postJson("/api/v1/discord/me/resource-shortfall-alerts/{$this->occurrence->id}/drafts", [
                'account_id' => $account->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fulfillment.mode', 'mmr_purchase_withdrawal')
            ->assertJsonPath('data.fulfillment.purchase_lines.coal.qty', '100.00')
            ->assertJsonPath('data.fulfillment.quoted_total', '200.00');

        $response = $this->withHeaders($this->headers('345678901234567894'))
            ->postJson('/api/v1/discord/me/resource-shortfall-fulfillments/'
                .$draft->json('data.fulfillment.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'dispatched')
            ->assertJsonPath('data.purchase.total_spent', '200.00')
            ->assertJsonPath('data.purchase.allocation_mode', MMRAssistantPurchase::ALLOCATION_MODE_ALERT_ON_DEMAND);

        $this->assertSame('800.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $purchase = MMRAssistantPurchase::query()->sole();
        $this->assertSame('100.00', number_format((float) $purchase->coal, 2, '.', ''));
        $this->assertSame(
            Transaction::query()->sole()->discord_action_intent_id,
            $purchase->discord_action_intent_id,
        );

        $this->withHeaders($this->headers('345678901234567894'))
            ->postJson('/api/v1/discord/me/resource-shortfall-fulfillments/'
                .$draft->json('data.fulfillment.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.transaction.id', $response->json('data.transaction.id'));

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('mmr_assistant_purchases', 1);
    }

    #[Test]
    public function assisted_confirmation_revalidates_mmr_availability_without_partial_changes(): void
    {
        SettingService::setMMRAssistantEnabled(true);
        MMRSetting::query()->create([
            'resource' => 'coal',
            'enabled' => true,
            'surcharge_pct' => 0,
        ]);
        $priceService = $this->createMock(TradePriceService::class);
        $priceService->method('get24hAverageWithSurcharge')->willReturn(['coal' => 2.00]);
        $this->app->instance(TradePriceService::class, $priceService);
        $account = $this->account(['money' => 1000, 'coal' => 20]);

        $draft = $this->withHeaders($this->headers('345678901234567895'))
            ->postJson("/api/v1/discord/me/resource-shortfall-alerts/{$this->occurrence->id}/drafts", [
                'account_id' => $account->id,
            ])
            ->assertCreated();

        SettingService::setMMRAssistantEnabled(false);

        $this->withHeaders($this->headers('345678901234567896'))
            ->postJson('/api/v1/discord/me/resource-shortfall-fulfillments/'
                .$draft->json('data.fulfillment.id').'/confirm')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'mmr_assistant_unavailable');

        $account->refresh();
        $this->assertSame('1000.00', number_format((float) $account->money, 2, '.', ''));
        $this->assertSame('20.00', number_format((float) $account->coal, 2, '.', ''));
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('mmr_assistant_purchases', 0);
    }

    #[Test]
    public function changed_account_balances_require_a_new_preview(): void
    {
        $account = $this->account(['coal' => 200]);
        $draft = $this->withHeaders($this->headers('345678901234567897'))
            ->postJson("/api/v1/discord/me/resource-shortfall-alerts/{$this->occurrence->id}/drafts", [
                'account_id' => $account->id,
            ])
            ->assertCreated();

        $account->forceFill(['coal' => 199])->save();

        $this->withHeaders($this->headers('345678901234567898'))
            ->postJson('/api/v1/discord/me/resource-shortfall-fulfillments/'
                .$draft->json('data.fulfillment.id').'/confirm')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'resource_shortfall_intent_stale');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function denied_assisted_withdrawal_returns_resources_but_keeps_the_purchase_final(): void
    {
        SettingService::setMMRAssistantEnabled(true);
        MMRSetting::query()->create([
            'resource' => 'coal',
            'enabled' => true,
            'surcharge_pct' => 0,
        ]);
        WithdrawLimit::query()->updateOrCreate(
            ['resource' => 'coal'],
            ['daily_limit' => '5.00'],
        );
        $priceService = $this->createMock(TradePriceService::class);
        $priceService->method('get24hAverageWithSurcharge')->willReturn(['coal' => 2.00]);
        $this->app->instance(TradePriceService::class, $priceService);
        $account = $this->account(['money' => 1000, 'coal' => 20]);

        $draft = $this->withHeaders($this->headers('345678901234567899'))
            ->postJson("/api/v1/discord/me/resource-shortfall-alerts/{$this->occurrence->id}/drafts", [
                'account_id' => $account->id,
            ])
            ->assertCreated();

        $this->withHeaders($this->headers('345678901234567900'))
            ->postJson('/api/v1/discord/me/resource-shortfall-fulfillments/'
                .$draft->json('data.fulfillment.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'pending_review');

        $transaction = Transaction::query()->sole();
        $purchase = MMRAssistantPurchase::query()->sole();
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => $this->nation->id + 100000]),
            ['manage-accounts'],
        );

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.deny', $transaction), ['reason' => 'Not approved'])
            ->assertSessionHas('alert-type', 'success');

        $account->refresh();
        $this->assertSame('800.00', number_format((float) $account->money, 2, '.', ''));
        $this->assertSame('120.00', number_format((float) $account->coal, 2, '.', ''));
        $this->assertNotNull($transaction->fresh()->denied_at);
        $this->assertDatabaseHas('mmr_assistant_purchases', [
            'id' => $purchase->id,
            'discord_action_intent_id' => $purchase->discord_action_intent_id,
        ]);
    }

    private function createNationResources(): void
    {
        $resources = collect(PWHelperService::resources(includeCredits: true))
            ->mapWithKeys(fn (string $resource): array => [$resource => 0])
            ->all();
        NationResources::query()->create([
            'nation_id' => $this->nation->id,
            ...$resources,
        ]);
    }

    private function createProjection(): void
    {
        NationProfitabilitySnapshot::query()->create([
            'nation_id' => $this->nation->id,
            'alliance_id' => $this->nation->alliance_id,
            'model_version' => EconomyRules::MODEL_VERSION,
            'leader_name' => $this->nation->leader_name,
            'nation_name' => $this->nation->nation_name,
            'cities' => $this->nation->num_cities,
            'resource_profit_per_day' => [
                ...array_fill_keys(EconomyRules::RESOURCE_KEYS, 0.0),
                'coal' => -120.0,
            ],
            'calculated_at' => now()->subHour(),
        ]);
    }

    /** @param array<string, float|int> $overrides */
    private function account(array $overrides): Account
    {
        $account = new Account;
        $account->nation_id = $this->nation->id;
        $account->name = 'Primary';
        foreach (PWHelperService::resources() as $resource) {
            $account->{$resource} = $overrides[$resource] ?? 0;
        }
        $account->save();

        return $account;
    }

    /** @return array<string, string> */
    private function headers(string $interactionId = '345678901234567890'): array
    {
        return $this->signedDiscordInteractionHeaders(
            'test-discord-bot-key',
            self::GUILD_ID,
            self::DISCORD_USER_ID,
            $interactionId,
            'withdraw',
        );
    }
}
