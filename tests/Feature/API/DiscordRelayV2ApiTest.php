<?php

namespace Tests\Feature\API;

use App\Enums\ApplicationStatus;
use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\GraphQL\Models\Nation as GraphQlNation;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Models\Application;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\DiscordCommandReceipt;
use App\Models\DiscordConnection;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\AlliancePositionService;
use App\Services\ApplicationService;
use App\Services\Discord\ApplicationDiscordReconciliationService;
use App\Services\Discord\DiscordCommandReceiptService;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Discord\Relay\CanonicalJson;
use App\Services\Discord\Relay\DiscordRelayProofVerifier;
use App\Services\Discord\Relay\StrictJson;
use App\Services\SettingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class DiscordRelayV2ApiTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private const APP_ID = '123456789012345678';

    private const BOT_TOKEN = 'relay-v2-bot-token';

    private const CONNECTION_ID = '11111111-2222-4333-8444-555555555555';

    private const DISCORD_USER_ID = '423456789012345678';

    private const GUILD_ID = '223456789012345678';

    private string $secretKey;

    private string $publicKey;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-08T12:00:10Z');
        $keypair = sodium_crypto_sign_seed_keypair(hash('sha256', static::class, true));
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);

        config([
            'services.discord_bot_key' => self::BOT_TOKEN,
            'services.discord.connection_mode' => DiscordConnectionMode::OfficialShared->value,
            'services.discord.relay_protocol_version' => 2,
            'services.discord.v1_reader_enabled' => true,
        ]);
        SettingService::setApplicationsEnabled(true);
        SettingService::setApplicationsDiscordApplicantRoleId('423456789012345679');
        SettingService::setApplicationsDiscordIaRoleId('423456789012345680');
        SettingService::setApplicationsDiscordMemberRoleId('423456789012345681');
        SettingService::setApplicationsDiscordInterviewCategoryId('423456789012345682');
        SettingService::setApplicationsApprovalAnnouncementChannelId('423456789012345683');
        SettingService::setApplicationsApprovalMessageTemplate('Welcome aboard');

        $nation = Nation::factory()->create();
        $this->actor = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_USER_ID,
            'unlinked_at' => null,
        ]);

        $this->createConnection();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_corrected_contract_fixture_verifies_with_the_deterministic_key(): void
    {
        DiscordConnection::query()->delete();
        $seed = implode('', array_map(chr(...), range(0, 31)));
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $public = sodium_crypto_sign_publickey($keypair);
        $this->createConnection([
            'relay_current_key_id' => 'relay-current-2026-01',
            'relay_current_public_key' => $this->base64Url($public),
        ]);

        $json = file_get_contents(base_path('tests/Fixtures/Discord/relay-proof-v2.interaction.json'));
        $this->assertIsString($json);
        $document = StrictJson::decode($json);
        $request = Request::create(
            '/api/v1/discord/staff/applications/opaque/approve?a=1&a=1&b=2',
            'POST',
            content: '',
        );

        $verified = app(DiscordRelayProofVerifier::class)->verify(
            $request,
            $document,
            $document['signature']['value'],
            (string) strtotime('2026-08-08T12:00:00Z'),
        );

        $this->assertSame(self::CONNECTION_ID, $verified->connection->connectionId);
        $this->assertSame('applications.approve', $verified->action);
        $this->assertSame(self::DISCORD_USER_ID, $verified->userId);
    }

    public function test_status_uses_nexus_authority_and_returns_sanitized_provider_diagnostics(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-diagnostic-info']);
        $headers = $this->interactionHeaders('GET', '/api/v1/discord/status', '', 'nexus', 'nexus.status');

        $this->withHeaders($headers)
            ->get('/api/v1/discord/status')
            ->assertOk()
            ->assertJsonPath('data.provider.authorization_authority', 'nexus')
            ->assertJsonPath('data.connection.connection_id', self::CONNECTION_ID)
            ->assertJsonPath('data.connection.generation', 7)
            ->assertJsonPath('data.capabilities.version', 1)
            ->assertJsonMissing(['relay_current_public_key'])
            ->assertJsonMissing(['signature']);
    }

    public function test_status_requires_nexus_diagnostic_permission(): void
    {
        $headers = $this->interactionHeaders('GET', '/api/v1/discord/status', '', 'nexus', 'nexus.status');

        $this->withHeaders($headers)
            ->get('/api/v1/discord/status')
            ->assertForbidden()
            ->assertJsonMissingPath('data.provider');
    }

    public function test_context_returns_only_safe_link_state_for_an_unlinked_actor(): void
    {
        DiscordAccount::query()->where('discord_id', self::DISCORD_USER_ID)->delete();
        $target = '/api/v1/discord/context';

        $this->withHeaders($this->interactionHeaders('GET', $target, '', 'me', 'me'))
            ->get($target)
            ->assertOk()
            ->assertJsonPath('data.contract_version', 1)
            ->assertJsonPath('data.state', 'unlinked')
            ->assertJsonPath('data.installation.connection_id', self::CONNECTION_ID)
            ->assertJsonPath('data.installation.generation', 7)
            ->assertJsonPath('data.installation.guild_id', self::GUILD_ID)
            ->assertJsonPath('meta.connection_id', self::CONNECTION_ID)
            ->assertJsonPath('meta.correlation_id', '323456789012345678')
            ->assertJsonMissingPath('data.identity')
            ->assertJsonMissingPath('data.actor')
            ->assertJsonMissingPath('data.permissions');
    }

    public function test_me_summary_supports_ready_and_unlinked_actors_without_actor_middleware(): void
    {
        $target = '/api/v1/discord/me/summary';
        $headers = $this->interactionHeaders('GET', $target, '', 'me', 'me');

        $this->withHeaders($headers)
            ->get($target)
            ->assertOk()
            ->assertJsonPath('data.contract_version', 1)
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.capabilities.revision', 1)
            ->assertJsonPath('data.profile_sync.state', 'unknown')
            ->assertJsonPath('meta.connection_generation', 7)
            ->assertJsonMissingPath('data.balances')
            ->assertJsonMissingPath('data.military');

        DiscordAccount::query()->where('discord_id', self::DISCORD_USER_ID)->delete();

        $this->withHeaders($this->interactionHeaders('GET', $target, '', 'me', 'me'))
            ->get($target)
            ->assertOk()
            ->assertJsonPath('data.state', 'unlinked')
            ->assertJsonPath('data.user_action.label', 'Sign in to '.config('app.name'))
            ->assertJsonMissingPath('data.identity');
    }

    public function test_unlinked_actor_can_preview_and_confirm_an_application_with_durable_reconciliation(): void
    {
        DiscordAccount::query()->delete();
        $this->app->instance(ApplicationService::class, $this->applicationService([
            877201 => $this->applicantNation(877201),
        ]));
        $previewPayload = [
            'nation_id' => 877201,
            'discord_username' => 'unlinked-applicant',
        ];
        $previewBody = json_encode($previewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $preview = $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/preview',
            $previewBody,
            'apply',
            'apply',
            interactionId: '533456789012345678',
        ))->postJson('/api/v1/discord/applications/preview', $previewPayload)
            ->assertCreated()
            ->assertJsonPath('data.intent.action', 'application.create')
            ->assertJsonPath('data.summary.nation.id', 877201)
            ->assertJsonPath('data.summary.continues_existing_application', false)
            ->assertJsonPath('meta.connection_id', self::CONNECTION_ID);

        $intentId = (string) $preview->json('data.intent.id');
        $confirmPayload = ['intent_id' => $intentId];
        $confirmBody = json_encode($confirmPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $confirm = $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/confirm',
            $confirmBody,
            'apply',
            'apply',
            interactionId: '543456789012345678',
        ))->postJson('/api/v1/discord/applications/confirm', $confirmPayload)
            ->assertCreated()
            ->assertJsonPath('data.application.nation_id', 877201)
            ->assertJsonPath('data.application.status', ApplicationStatus::Pending->value)
            ->assertJsonPath('data.reconciliation.state', 'queued')
            ->assertJsonPath('meta.idempotent_replay', false);

        $applicationId = $confirm->json('data.application.id');
        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'discord_user_id' => self::DISCORD_USER_ID,
            'discord_connection_id' => self::CONNECTION_ID,
            'discord_connection_generation' => 7,
            'discord_application_id' => self::APP_ID,
            'discord_guild_id' => self::GUILD_ID,
        ]);
        $this->assertSame(1, DiscordQueue::query()
            ->where('action', ApplicationDiscordReconciliationService::ACTION)
            ->count());
        $this->assertDatabaseHas('discord_action_intents', [
            'discord_user_id' => self::DISCORD_USER_ID,
            'connection_id' => self::CONNECTION_ID,
            'connection_generation' => 7,
            'application_id' => self::APP_ID,
            'status' => DiscordActionIntent::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('discord_command_receipts', [
            'interaction_id' => '543456789012345678',
            'discord_user_id' => self::DISCORD_USER_ID,
            'user_id' => null,
            'connection_id' => self::CONNECTION_ID,
            'connection_generation' => 7,
            'status' => DiscordCommandReceipt::STATUS_COMPLETED,
        ]);

        $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/confirm',
            $confirmBody,
            'apply',
            'apply',
            interactionId: '543456789012345678',
            discordUserId: '613456789012345678',
        ))->postJson('/api/v1/discord/applications/confirm', $confirmPayload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'discord_interaction_conflict');

        $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/confirm',
            $confirmBody,
            'apply',
            'apply',
            interactionId: '553456789012345678',
        ))->postJson('/api/v1/discord/applications/confirm', $confirmPayload)
            ->assertCreated()
            ->assertJsonPath('data.application.id', $applicationId)
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('applications', 1);
        $this->assertSame(1, DiscordQueue::query()
            ->where('action', ApplicationDiscordReconciliationService::ACTION)
            ->count());
    }

    public function test_application_confirmation_revalidates_a_stale_preview(): void
    {
        DiscordAccount::query()->delete();
        $this->app->instance(ApplicationService::class, $this->applicationService([
            877202 => $this->applicantNation(877202),
        ]));
        $previewPayload = ['nation_id' => 877202, 'discord_username' => 'stale-applicant'];
        $previewBody = json_encode($previewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $preview = $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/preview',
            $previewBody,
            'apply',
            'apply',
            interactionId: '563456789012345678',
        ))->postJson('/api/v1/discord/applications/preview', $previewPayload)->assertCreated();

        $changed = $this->applicantNation(877202);
        $changed->leader_name = 'Changed after preview';
        $this->app->instance(ApplicationService::class, $this->applicationService([877202 => $changed]));
        $confirmPayload = ['intent_id' => $preview->json('data.intent.id')];
        $confirmBody = json_encode($confirmPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/confirm',
            $confirmBody,
            'apply',
            'apply',
            interactionId: '573456789012345678',
        ))->postJson('/api/v1/discord/applications/confirm', $confirmPayload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'application_preview_stale')
            ->assertJsonPath('error.details.retryable', false);

        $this->assertDatabaseCount('applications', 0);
        $this->assertSame(DiscordActionIntent::STATUS_DRAFT, DiscordActionIntent::query()->firstOrFail()->status);
    }

    public function test_application_preview_fails_closed_without_reconciliation_capability(): void
    {
        DiscordAccount::query()->delete();
        DiscordConnection::query()->update(['capabilities' => ['relay.proof.v2' => true]]);
        $this->app->instance(ApplicationService::class, $this->applicationService([
            877203 => $this->applicantNation(877203),
        ]));
        $payload = ['nation_id' => 877203, 'discord_username' => 'unsupported-applicant'];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/preview',
            $body,
            'apply',
            'apply',
            interactionId: '583456789012345678',
        ))->postJson('/api/v1/discord/applications/preview', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'application_reconciliation_unavailable');

        $this->assertDatabaseCount('discord_action_intents', 0);
    }

    public function test_application_confirmation_rechecks_revoked_reconciliation_capability(): void
    {
        DiscordAccount::query()->delete();
        $this->app->instance(ApplicationService::class, $this->applicationService([
            877204 => $this->applicantNation(877204),
        ]));
        $previewPayload = ['nation_id' => 877204, 'discord_username' => 'revoked-applicant'];
        $previewBody = json_encode($previewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $preview = $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/preview',
            $previewBody,
            'apply',
            'apply',
            interactionId: '593456789012345678',
        ))->postJson('/api/v1/discord/applications/preview', $previewPayload)->assertCreated();

        DiscordConnection::query()->update(['capabilities' => ['relay.proof.v2' => true]]);
        $confirmPayload = ['intent_id' => $preview->json('data.intent.id')];
        $confirmBody = json_encode($confirmPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->withHeaders($this->interactionHeaders(
            'POST',
            '/api/v1/discord/applications/confirm',
            $confirmBody,
            'apply',
            'apply',
            interactionId: '603456789012345678',
        ))->postJson('/api/v1/discord/applications/confirm', $confirmPayload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'application_reconciliation_unavailable');

        $this->assertDatabaseCount('applications', 0);
        $this->assertSame(DiscordActionIntent::STATUS_DRAFT, DiscordActionIntent::query()->firstOrFail()->status);
    }

    public function test_staff_application_views_are_isolated_by_connection_and_hide_ambiguous_legacy_rows(): void
    {
        $this->actor->forceFill(['is_admin' => true])->save();
        $this->actor = $this->grantPermissions($this->actor, ['manage-applications']);
        $foreignConnection = $this->createConnection([
            'id' => '21111111-2222-4333-8444-555555555555',
            'application_id' => '133456789012345678',
            'guild_id' => '233456789012345678',
        ]);
        $current = $this->application([
            'nation_id' => 9001,
            'discord_user_id' => '523456789012345678',
            'discord_connection_id' => self::CONNECTION_ID,
            'discord_connection_generation' => 7,
            'discord_application_id' => self::APP_ID,
            'discord_guild_id' => self::GUILD_ID,
        ]);
        $foreign = $this->application([
            'nation_id' => 9002,
            'discord_user_id' => '623456789012345678',
            'discord_connection_id' => $foreignConnection->id,
            'discord_connection_generation' => $foreignConnection->generation,
            'discord_application_id' => $foreignConnection->application_id,
            'discord_guild_id' => $foreignConnection->guild_id,
        ]);
        $this->application([
            'nation_id' => 9003,
            'discord_user_id' => '723456789012345678',
        ]);
        $target = '/api/v1/discord/staff/applications';

        $this->withHeaders($this->interactionHeaders('GET', $target, '', 'applications', 'applications'))
            ->get($target)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id);

        $foreignTarget = $target.'/'.$foreign->id;
        $this->withHeaders($this->interactionHeaders('GET', $foreignTarget, '', 'applications', 'applications'))
            ->get($foreignTarget)
            ->assertNotFound();
    }

    public function test_config_backed_dedicated_v2_connection_requires_no_cloud_state(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-diagnostic-info']);
        DiscordConnection::query()->delete();
        config([
            'services.discord.connection_mode' => DiscordConnectionMode::Dedicated->value,
            'services.discord.connection_id' => self::CONNECTION_ID,
            'services.discord.application_id' => self::APP_ID,
            'services.discord.guild_id' => self::GUILD_ID,
            'services.discord.connection_generation' => 7,
            'services.discord.relay_protocol_version' => 2,
            'services.discord.relay_current_key_id' => 'relay-current',
            'services.discord.relay_current_public_key' => $this->base64Url($this->publicKey),
            'services.discord.capabilities' => ['status.provider-diagnostics.v1'],
        ]);

        $this->withHeaders($this->interactionHeaders(
            'GET',
            '/api/v1/discord/status',
            '',
            'nexus',
            'nexus.status',
        ))->get('/api/v1/discord/status')
            ->assertOk()
            ->assertJsonPath('data.connection.mode', 'dedicated')
            ->assertJsonMissingPath('data.connection.cloud');
    }

    public function test_forged_cross_guild_and_stale_generation_proofs_fail_closed(): void
    {
        $forged = $this->interactionDocument('GET', '/api/v1/discord/status', '', 'nexus', 'nexus.status');
        $forgedHeaders = $this->headersForDocument($forged, false);

        $this->withHeaders($forgedHeaders)
            ->get('/api/v1/discord/status')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_discord_relay_signature');

        $crossGuild = $this->interactionDocument('GET', '/api/v1/discord/status', '', 'nexus', 'nexus.status');
        $crossGuild['guild_id'] = '999999999999999999';
        $this->withHeaders($this->headersForDocument($crossGuild))
            ->get('/api/v1/discord/status')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_connection_binding_mismatch');

        $stale = $this->interactionDocument('GET', '/api/v1/discord/status', '', 'nexus', 'nexus.status');
        $stale['generation'] = 6;
        $this->withHeaders($this->headersForDocument($stale))
            ->get('/api/v1/discord/status')
            ->assertConflict()
            ->assertJsonPath('error.code', 'stale_discord_connection_generation');
    }

    public function test_request_target_body_and_header_bindings_cannot_be_changed(): void
    {
        $headers = $this->interactionHeaders('GET', '/api/v1/discord/status?a=1&b=2', '', 'nexus', 'nexus.status');
        $this->withHeaders($headers)
            ->get('/api/v1/discord/status?b=2&a=1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'discord_relay_request_binding_mismatch');

        $headers['X-Nexus-Discord-Relay-Timestamp'] = (string) (now()->timestamp - 1);
        $this->withHeaders($headers)
            ->get('/api/v1/discord/status?a=1&b=2')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'discord_relay_header_mismatch');
    }

    public function test_next_key_is_accepted_only_after_its_activation_time(): void
    {
        $this->actor = $this->grantPermissions($this->actor, ['view-diagnostic-info']);
        $nextPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'next-key', true));
        $nextSecret = sodium_crypto_sign_secretkey($nextPair);
        $nextPublic = sodium_crypto_sign_publickey($nextPair);
        DiscordConnection::query()->firstOrFail()->forceFill([
            'relay_next_key_id' => 'relay-next',
            'relay_next_public_key' => $this->base64Url($nextPublic),
            'relay_next_activates_at' => now()->addMinute(),
        ])->save();

        $document = $this->interactionDocument('GET', '/api/v1/discord/status', '', 'nexus', 'nexus.status');
        $document['key_id'] = 'relay-next';
        $before = $this->headersForDocument($document, true, $nextSecret);
        $this->withHeaders($before)
            ->get('/api/v1/discord/status')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unknown_discord_relay_key');

        Carbon::setTestNow(now()->addMinutes(2));
        $after = $this->interactionHeaders(
            'GET',
            '/api/v1/discord/status',
            '',
            'nexus',
            'nexus.status',
            'relay-next',
            $nextSecret,
        );
        $this->withHeaders($after)->get('/api/v1/discord/status')->assertOk();
    }

    public function test_database_enforces_one_active_connection_per_guild(): void
    {
        $this->expectException(QueryException::class);

        $this->createConnection([
            'id' => (string) Str::uuid(),
            'application_id' => '323456789012345678',
            'generation' => 8,
        ]);
    }

    public function test_duplicate_json_members_and_conflicting_service_nonce_replays_are_rejected(): void
    {
        $this->expectException(JsonException::class);
        StrictJson::decode('{"contract":"relay-proof","contract":"relay-proof"}');
    }

    public function test_service_nonce_cannot_be_reused_for_a_different_queue_claim(): void
    {
        $firstBody = json_encode([
            'worker_id' => 'aaaaaaaa-1111-4111-8111-111111111111',
            'request_id' => 'bbbbbbbb-2222-4222-8222-222222222222',
            'connection_id' => self::CONNECTION_ID,
            'application_id' => self::APP_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $secondBody = str_replace(
            'bbbbbbbb-2222-4222-8222-222222222222',
            'cccccccc-3333-4333-8333-333333333333',
            $firstBody,
        );
        $nonce = 'dddddddd-4444-4444-8444-444444444444';

        $first = $this->withHeaders($this->serviceHeaders(
            'POST',
            '/api/v1/discord/queue/claim',
            $firstBody,
            'queue.claim',
            $nonce,
        ))->postJson('/api/v1/discord/queue/claim', json_decode($firstBody, true, flags: JSON_THROW_ON_ERROR));
        $first->assertOk();

        $this->withHeaders($this->serviceHeaders(
            'POST',
            '/api/v1/discord/queue/claim',
            $secondBody,
            'queue.claim',
            $nonce,
        ))->postJson(
            '/api/v1/discord/queue/claim',
            json_decode($secondBody, true, flags: JSON_THROW_ON_ERROR),
        )->assertConflict()->assertJsonPath('error.code', 'replayed_discord_service_proof');
    }

    public function test_active_v2_connection_disables_the_unsigned_legacy_queue_bypass(): void
    {
        $this->withToken(self::BOT_TOKEN)
            ->postJson('/api/v1/discord/queue/claim', [
                'worker_id' => (string) Str::uuid(),
                'request_id' => (string) Str::uuid(),
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'discord_relay_proof_required');
    }

    public function test_signed_idempotency_key_is_connection_scoped_and_conflict_checked(): void
    {
        $context = app(DiscordConnectionResolver::class)->resolveForVerification(self::CONNECTION_ID);
        $idempotencyKey = 'eeeeeeee-5555-4555-8555-555555555555';
        $first = Request::create('/api/v1/discord/test', 'POST', ['value' => 'one']);
        $first->headers->set('X-Discord-Interaction-ID', '523456789012345678');
        $first->headers->set('X-Discord-Guild-ID', self::GUILD_ID);
        $first->headers->set('X-Discord-User-ID', self::DISCORD_USER_ID);
        $first->attributes->set(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE, $context);
        $first->attributes->set(VerifyDiscordInteraction::GENERATION_ATTRIBUTE, 7);
        $first->attributes->set(VerifyDiscordInteraction::IDEMPOTENCY_ATTRIBUTE, $idempotencyKey);

        $claimed = app(DiscordCommandReceiptService::class)->claim($first, $this->actor);
        $this->assertNotNull($claimed['receipt']);
        $this->assertNull($claimed['response']);

        $conflict = Request::create('/api/v1/discord/test', 'POST', ['value' => 'two']);
        $conflict->headers->set('X-Discord-Interaction-ID', '623456789012345678');
        $conflict->headers->set('X-Discord-Guild-ID', self::GUILD_ID);
        $conflict->headers->set('X-Discord-User-ID', self::DISCORD_USER_ID);
        $conflict->attributes->set(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE, $context);
        $conflict->attributes->set(VerifyDiscordInteraction::GENERATION_ATTRIBUTE, 7);
        $conflict->attributes->set(VerifyDiscordInteraction::IDEMPOTENCY_ATTRIBUTE, $idempotencyKey);

        $reused = app(DiscordCommandReceiptService::class)->claim($conflict, $this->actor);
        $this->assertNull($reused['receipt']);
        $this->assertSame(409, $reused['response']?->getStatusCode());
    }

    /** @param array<string, mixed> $overrides */
    private function createConnection(array $overrides = []): DiscordConnection
    {
        return DiscordConnection::query()->create(array_merge([
            'id' => self::CONNECTION_ID,
            'mode' => DiscordConnectionMode::OfficialShared,
            'state' => DiscordConnectionState::Active,
            'application_id' => self::APP_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
            'protocol_version' => 2,
            'relay_current_key_id' => 'relay-current',
            'relay_current_public_key' => $this->base64Url($this->publicKey),
            'capability_version' => 1,
            'capabilities' => [
                'capabilities' => [
                    'relay.proof.v2' => true,
                    'queue.leases.v1' => true,
                    'queue.connection-context.v1' => true,
                    'status.provider-diagnostics.v1' => true,
                ],
                'supported_queue_actions' => [
                    ApplicationDiscordReconciliationService::ACTION,
                ],
            ],
            'v1_reader_enabled' => true,
            'activated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function application(array $overrides): Application
    {
        return Application::query()->create(array_merge([
            'leader_name_snapshot' => 'Example Leader',
            'discord_username' => 'example-user',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ], $overrides));
    }

    private function interactionHeaders(
        string $method,
        string $target,
        string $body,
        string $command,
        string $action,
        string $keyId = 'relay-current',
        ?string $secret = null,
        ?string $interactionId = null,
        ?string $discordUserId = null,
        ?string $idempotencyKey = null,
    ): array {
        $document = $this->interactionDocument(
            $method,
            $target,
            $body,
            $command,
            $action,
            $interactionId,
            $discordUserId,
            $idempotencyKey,
        );
        $document['key_id'] = $keyId;

        return $this->headersForDocument($document, true, $secret);
    }

    /** @return array<string, string> */
    private function serviceHeaders(
        string $method,
        string $target,
        string $body,
        string $action,
        string $nonce,
    ): array {
        $document = [
            'contract' => 'relay-proof',
            'contract_version' => 2,
            'issuer' => 'discord-relay',
            'audience' => 'nexus',
            'key_scope' => 'discord-relay->nexus',
            'connection_id' => self::CONNECTION_ID,
            'app_id' => self::APP_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
            'key_id' => 'relay-current',
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->addSeconds(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => (string) Str::uuid(),
            'proof' => [
                'type' => 'service',
                'action' => $action,
                'nonce' => $nonce,
            ],
            'method' => strtoupper($method),
            'normalized_path_query' => $target,
            'body_sha256' => hash('sha256', $body),
        ];

        return $this->headersForDocument($document);
    }

    /** @return array<string, mixed> */
    private function interactionDocument(
        string $method,
        string $target,
        string $body,
        string $command,
        string $action,
        ?string $interactionId = null,
        ?string $discordUserId = null,
        ?string $idempotencyKey = null,
    ): array {
        return [
            'contract' => 'relay-proof',
            'contract_version' => 2,
            'issuer' => 'discord-relay',
            'audience' => 'nexus',
            'key_scope' => 'discord-relay->nexus',
            'connection_id' => self::CONNECTION_ID,
            'app_id' => self::APP_ID,
            'guild_id' => self::GUILD_ID,
            'generation' => 7,
            'key_id' => 'relay-current',
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->addSeconds(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            'proof' => [
                'type' => 'interaction',
                'interaction_id' => $interactionId ?? '323456789012345678',
                'user_id' => $discordUserId ?? self::DISCORD_USER_ID,
                'command' => $command,
                'action' => $action,
            ],
            'method' => strtoupper($method),
            'normalized_path_query' => $target,
            'body_sha256' => hash('sha256', $body),
        ];
    }

    /** @param array<string, mixed> $document @return array<string, string> */
    private function headersForDocument(
        array $document,
        bool $sign = true,
        ?string $secret = null,
    ): array {
        unset($document['signature']);
        $signature = sodium_crypto_sign_detached(
            "NEXUS-DISCORD-RELAY-PROOF-V2\n".CanonicalJson::encode($document),
            $secret ?? $this->secretKey,
        );
        $document['signature'] = [
            'algorithm' => 'ed25519',
            'value' => bin2hex($signature),
        ];
        if (! $sign) {
            $document['guild_id'] = '999999999999999999';
        }
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'Authorization' => 'Bearer '.self::BOT_TOKEN,
            'Accept' => 'application/json',
            'X-Nexus-Discord-Relay-Payload' => $this->base64Url($json),
            'X-Nexus-Discord-Relay-Signature' => $document['signature']['value'],
            'X-Nexus-Discord-Relay-Timestamp' => (string) strtotime($document['issued_at']),
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function applicantNation(int $nationId): GraphQlNation
    {
        $nation = new GraphQlNation;
        $nation->id = $nationId;
        $nation->nation_name = "Nation {$nationId}";
        $nation->leader_name = "Leader {$nationId}";
        $nation->alliance_id = app(AllianceMembershipService::class)->getPrimaryAllianceId();
        $nation->alliance_position = 'APPLICANT';

        return $nation;
    }

    /** @param array<int, GraphQlNation> $nations */
    private function applicationService(array $nations): ApplicationService
    {
        $membershipService = app(AllianceMembershipService::class);
        $alliancePositionService = $this->createMock(AlliancePositionService::class);

        return new class($membershipService, $alliancePositionService, $nations) extends ApplicationService
        {
            /** @param array<int, GraphQlNation> $nations */
            public function __construct(
                AllianceMembershipService $membershipService,
                AlliancePositionService $alliancePositionService,
                private readonly array $nations,
            ) {
                parent::__construct($membershipService, $alliancePositionService);
            }

            protected function fetchLiveNation(int $nationId): GraphQlNation
            {
                return $this->nations[$nationId];
            }
        };
    }
}
