<?php

namespace Tests\Feature\API;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Http\Controllers\API\Discord\AlertSubscriptionController as DiscordAlertSubscriptionController;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\ValidateDiscordBotAPI;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\Alerts\AlertUserSettingsService;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordAlertSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_ID = '234567890123456789';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        $alliance = Alliance::factory()->create();
        config([
            'services.discord_bot_key' => 'alerts-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'services.pw.alliance_id' => $alliance->id,
        ]);
        Cache::flush();
        app(AllianceMembershipService::class)->refresh();

        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $this->actor = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);

        Route::middleware([
            ValidateDiscordBotAPI::class,
            VerifyDiscordInteraction::class,
            ResolveDiscordActor::class,
            SubstituteBindings::class,
        ])->prefix('api/v1/discord/me/alerts')->group(function (): void {
            Route::put('/settings', [DiscordAlertSubscriptionController::class, 'updateSettings']);
            Route::get('/settings', [DiscordAlertSubscriptionController::class, 'settings']);
            Route::get('/activity', [DiscordAlertSubscriptionController::class, 'activity']);
            Route::patch('/activity/{alertDelivery}/read', [DiscordAlertSubscriptionController::class, 'markActivityRead']);
            Route::post('/preview', [DiscordAlertSubscriptionController::class, 'preview']);
            Route::post('/test', [DiscordAlertSubscriptionController::class, 'testDraft']);
            Route::get('/deliveries/{alertDelivery}', [DiscordAlertSubscriptionController::class, 'delivery']);
            Route::put('/{alertSubscription}', [DiscordAlertSubscriptionController::class, 'update']);
        });
    }

    public function test_member_can_create_list_pause_and_delete_own_alert(): void
    {
        $created = $this->withHeaders($this->headers('345678901234567890'))
            ->postJson('/api/v1/discord/me/alerts', [
                'type' => 'nation',
                'target_id' => $this->actor->nation_id,
                'events' => ['beige_exited'],
                'cooldown_minutes' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.active', true);

        $alertId = $created->json('data.id');

        $this->withHeaders($this->headers('456789012345678901'))
            ->getJson('/api/v1/discord/me/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alertId)
            ->assertJsonPath('data.0.delivery.mode', 'immediate')
            ->assertJsonPath('data.0.delivery.discord_enabled', false)
            ->assertJsonPath('data.0.delivery.health', 'subscription_disabled')
            ->assertJsonPath('meta.capabilities.0', 'alerts.preferences.v2');

        $this->withHeaders($this->headers('567890123456789012'))
            ->patchJson('/api/v1/discord/me/alerts/'.$alertId.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.condition', 'Nation beige exited')
            ->assertJsonPath('data.last_triggered_at', null);

        $this->withHeaders($this->headers('678901234567890123'))
            ->deleteJson('/api/v1/discord/me/alerts/'.$alertId)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('alert_subscriptions', ['id' => $alertId]);
    }

    public function test_member_can_preview_update_and_test_a_draft_without_changing_a_baseline(): void
    {
        app(AlertUserSettingsService::class)->update($this->actor, [
            'timezone' => 'America/Chicago',
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'default_digest_time' => '09:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => true,
        ]);
        $payload = [
            'type' => 'nation',
            'name' => 'City watch',
            'target_id' => $this->actor->nation_id,
            'events' => ['nation.city_count.changed'],
            'cooldown_minutes' => 45,
            'delivery_mode' => 'daily',
            'discord_enabled' => true,
            'timezone' => 'America/Chicago',
        ];

        $this->withHeaders($this->headers('901234567890123456'))
            ->postJson('/api/v1/discord/me/alerts/preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'City watch')
            ->assertJsonPath('data.events.0.key', 'nation.city_count.changed')
            ->assertJsonPath('data.delivery.mode', 'daily')
            ->assertJsonPath('data.baseline_state', 'established_after_save');

        $this->assertDatabaseCount('alert_subscriptions', 0);
        $this->assertDatabaseCount('alert_occurrences', 0);

        $test = $this->withHeaders($this->headers('912345678901234567'))
            ->postJson('/api/v1/discord/me/alerts/test', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.is_test', true)
            ->assertJsonPath('data.event_key', 'nation.city_count.changed')
            ->assertJsonPath('data.deliveries.0.destination_kind', 'web');

        $this->assertNotNull($test->json('data.delivery_ids'));
        $this->assertDatabaseCount('alert_subscriptions', 0);
        $this->assertDatabaseCount('alert_occurrences', 1);

        $subscription = app(AlertSubscriptionService::class)->createForUser($this->actor, $payload);
        $subscription->forceFill([
            'last_observed_state' => ['cities' => 11],
            'last_condition' => true,
            'last_evaluated_at' => now(),
        ])->save();

        $updatedPayload = [
            ...$payload,
            'name' => 'City watch renamed',
            'delivery_mode' => 'weekly',
        ];
        $this->withHeaders($this->headers('923456789012345678'))
            ->putJson('/api/v1/discord/me/alerts/'.$subscription->id, $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.name', 'City watch renamed')
            ->assertJsonPath('data.delivery.mode', 'weekly');

        $this->assertSame(['cities' => 11], $subscription->refresh()->last_observed_state);
    }

    public function test_legacy_subscription_config_is_projected_with_canonical_events_without_a_backfill_write(): void
    {
        $legacy = AlertSubscription::query()->create([
            'user_id' => $this->actor->id,
            'type' => 'nation',
            'config' => [
                'target_id' => $this->actor->nation_id,
                'events' => ['beige_exited'],
            ],
            'is_active' => true,
            'cooldown_minutes' => 60,
        ]);

        $this->withHeaders($this->headers('928456789012345678'))
            ->getJson('/api/v1/discord/me/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $legacy->id)
            ->assertJsonPath('data.0.events.0.key', 'nation.beige.exited')
            ->assertJsonPath('data.0.condition', 'Nation beige exited');

        $this->assertDatabaseCount('alert_subscription_events', 0);
    }

    public function test_market_subscription_projection_exposes_only_typed_edit_filters(): void
    {
        $market = app(AlertSubscriptionService::class)->createForUser($this->actor, [
            'type' => 'market',
            'name' => 'Oil threshold',
            'resource' => 'oil',
            'direction' => 'above',
            'threshold' => 123.45,
            'cooldown_minutes' => 30,
        ]);
        $market->forceFill([
            'config' => [
                ...$market->config,
                'provider_account' => 'must-not-leak',
            ],
        ])->save();

        $response = $this->withHeaders($this->headers('929456789012345678'))
            ->getJson('/api/v1/discord/me/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $market->id)
            ->assertJsonPath('data.0.filter.resource', 'oil')
            ->assertJsonPath('data.0.filter.direction', 'above')
            ->assertJsonPath('data.0.filter.threshold', 123.45);

        $this->assertSame([
            'resource' => 'oil',
            'direction' => 'above',
            'threshold' => 123.45,
        ], $response->json('data.0.filter'));
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
    }

    public function test_member_can_manage_quiet_hours_and_read_owner_scoped_activity_receipts(): void
    {
        $this->withHeaders($this->headers('934567890123456789'))
            ->putJson('/api/v1/discord/me/alerts/settings', [
                'timezone' => 'America/Chicago',
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '07:30',
                'default_digest_time' => '09:15',
                'default_digest_weekday' => 5,
                'discord_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'America/Chicago')
            ->assertJsonPath('data.quiet_hours.enabled', true)
            ->assertJsonPath('data.quiet_hours.start', '22:00')
            ->assertJsonPath('data.default_digest.weekday', 5);

        $this->withHeaders($this->headers('945678901234567890'))
            ->getJson('/api/v1/discord/me/alerts/settings')
            ->assertOk()
            ->assertJsonPath('data.discord_enabled', true);

        $occurrence = AlertOccurrence::factory()->create([
            'audience_user_id' => $this->actor->id,
            'event_key' => 'market.price.crossed',
            'payload' => [
                'resource' => 'steel',
                'direction' => 'below',
                'threshold' => 3000,
                'price' => 2900,
                'observed_at' => now()->toIso8601String(),
            ],
        ]);
        $webDelivery = AlertDelivery::factory()->create([
            'alert_occurrence_id' => $occurrence->id,
            'recipient_user_id' => $this->actor->id,
            'destination_kind' => AlertDestinationKind::Web,
            'status' => AlertDeliveryStatus::Delivered,
            'read_at' => null,
        ]);

        $this->withHeaders($this->headers('956789012345678901'))
            ->getJson('/api/v1/discord/me/alerts/activity?limit=10')
            ->assertOk()
            ->assertJsonPath('data.items.0.activity_id', $webDelivery->id)
            ->assertJsonPath('data.items.0.event_label', 'Market price crossed')
            ->assertJsonPath('meta.capabilities.0', 'alerts.activity.v1');

        $this->withHeaders($this->headers('967890123456789012'))
            ->patchJson('/api/v1/discord/me/alerts/activity/'.$webDelivery->id.'/read', ['read' => true])
            ->assertOk()
            ->assertJsonPath('data.activity_id', $webDelivery->id);
        $this->assertNotNull($webDelivery->refresh()->read_at);

        $this->withHeaders($this->headers('978901234567890123'))
            ->getJson('/api/v1/discord/me/alerts/deliveries/'.$webDelivery->id)
            ->assertOk()
            ->assertJsonPath('data.id', $webDelivery->id)
            ->assertJsonMissingPath('data.destination_snapshot');

        $otherDelivery = AlertDelivery::factory()->create(['recipient_user_id' => User::factory()->create()->id]);
        $this->withHeaders($this->headers('989012345678901234'))
            ->getJson('/api/v1/discord/me/alerts/deliveries/'.$otherDelivery->id)
            ->assertForbidden();
    }

    public function test_unsaved_alert_tests_are_rate_limited_per_member(): void
    {
        $payload = [
            'type' => 'market',
            'resource' => 'steel',
            'direction' => 'below',
            'threshold' => 3000,
            'discord_enabled' => false,
        ];

        foreach ([
            '101234567890123456',
            '112345678901234567',
            '123456789012345679',
        ] as $interactionId) {
            $this->withHeaders($this->headers($interactionId))
                ->postJson('/api/v1/discord/me/alerts/test', $payload)
                ->assertAccepted();
        }

        $this->withHeaders($this->headers('134567890123456789'))
            ->postJson('/api/v1/discord/me/alerts/test', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'rate_limited');

        $this->assertDatabaseCount('alert_subscriptions', 0);
        $this->assertDatabaseCount('alert_occurrences', 3);
    }

    public function test_applicant_and_non_owner_cannot_manage_alerts(): void
    {
        $otherUser = User::factory()->verified()->create([
            'nation_id' => Nation::factory()->create([
                'alliance_id' => $this->actor->nation->alliance_id,
                'alliance_position' => 'MEMBER',
            ])->id,
        ]);
        $otherAlert = AlertSubscription::query()->create([
            'user_id' => $otherUser->id,
            'type' => 'market',
            'config' => ['resource' => 'steel', 'direction' => 'above', 'threshold' => 4000],
            'is_active' => true,
            'cooldown_minutes' => 60,
        ]);

        $this->withHeaders($this->headers('789012345678901234'))
            ->patchJson('/api/v1/discord/me/alerts/'.$otherAlert->id.'/status', ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->actor->nation()->update(['alliance_position' => 'APPLICANT']);

        $this->withHeaders($this->headers('890123456789012345'))
            ->getJson('/api/v1/discord/me/alerts')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    /** @return array<string, string> */
    private function headers(string $interactionId): array
    {
        return $this->signedDiscordInteractionHeaders(
            'alerts-test-key',
            self::GUILD_ID,
            self::DISCORD_ID,
            $interactionId,
            'alerts',
        );
    }
}
