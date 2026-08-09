<?php

namespace Tests\Feature\Services;

use App\Enums\DiscordConnectionMode;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\User;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordWorkflowIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiscordWorkflowIntentServiceTest extends TestCase
{
    use RefreshDatabase;

    private const GUILD_ID = '123456789012345678';

    private const APPLICATION_ID = '223456789012345678';

    private const DISCORD_USER_ID = '323456789012345678';

    private DiscordWorkflowIntentService $service;

    private User $actor;

    private DiscordAccount $discordAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DiscordWorkflowIntentService::class);
        $this->actor = User::factory()->create();
        $this->discordAccount = DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_USER_ID,
            'unlinked_at' => null,
        ]);
    }

    public function test_it_persists_and_resolves_an_exact_connection_binding(): void
    {
        $connection = $this->sharedConnection();
        $intent = $this->service->create(
            $this->actor,
            $this->discordAccount,
            self::GUILD_ID,
            '423456789012345678',
            'test.action',
            ['safe' => true],
            $connection,
        );

        $this->assertSame(self::DISCORD_USER_ID, $intent->discord_user_id);
        $this->assertSame($connection->connectionId, $intent->connection_id);
        $this->assertSame($connection->generation, $intent->connection_generation);
        $this->assertSame($connection->applicationId, $intent->application_id);
        $this->assertSame(
            $intent->id,
            $this->service->get(
                $this->actor,
                self::GUILD_ID,
                (string) $intent->presentedToken,
                'test.action',
                $connection,
            )->id,
        );
    }

    public function test_it_rejects_foreign_connections_and_stale_generations(): void
    {
        $intent = $this->service->create(
            $this->actor,
            $this->discordAccount,
            self::GUILD_ID,
            '523456789012345678',
            'test.action',
            [],
            $this->sharedConnection(),
        );

        foreach ([
            $this->sharedConnection(connectionId: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),
            $this->sharedConnection(generation: 8),
            $this->sharedConnection(applicationId: '623456789012345678'),
        ] as $foreignConnection) {
            try {
                $this->service->get(
                    $this->actor,
                    self::GUILD_ID,
                    (string) $intent->presentedToken,
                    'test.action',
                    $foreignConnection,
                );
                $this->fail('A foreign or stale connection resolved the workflow intent.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    'Action intent not found.',
                    $exception->errors()['intent_id'][0] ?? null,
                );
            }
        }
    }

    public function test_it_rejects_foreign_guilds_and_linked_actors(): void
    {
        $intent = $this->service->create(
            $this->actor,
            $this->discordAccount,
            self::GUILD_ID,
            '723456789012345678',
            'test.action',
            [],
            $this->sharedConnection(),
        );
        $otherActor = User::factory()->create();

        foreach ([
            [$this->actor, '823456789012345678', $this->sharedConnection(guildId: '823456789012345678')],
            [$otherActor, self::GUILD_ID, $this->sharedConnection()],
        ] as [$actor, $guildId, $connection]) {
            try {
                $this->service->get(
                    $actor,
                    $guildId,
                    (string) $intent->presentedToken,
                    'test.action',
                    $connection,
                );
                $this->fail('A foreign guild or actor resolved the workflow intent.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    'Action intent not found.',
                    $exception->errors()['intent_id'][0] ?? null,
                );
            }
        }
    }

    public function test_unlinked_discord_users_can_create_and_consume_one_time_intents(): void
    {
        $connection = $this->sharedConnection();
        $intent = $this->service->createForDiscordUser(
            '923456789012345678',
            self::GUILD_ID,
            '933456789012345678',
            'application.create',
            ['nation_id' => 42],
            $connection,
        );
        $result = User::factory()->create();

        $confirmed = $this->service->consumeForDiscordUser(
            '923456789012345678',
            self::GUILD_ID,
            (string) $intent->presentedToken,
            'application.create',
            function (array $payload) use ($result): User {
                $this->assertSame(['nation_id' => 42], $payload);

                return $result;
            },
            $connection,
        );

        $this->assertSame($result->id, $confirmed->id);
        $this->assertNull($intent->fresh()->user_id);
        $this->assertSame(DiscordActionIntent::STATUS_CONFIRMED, $intent->fresh()->status);
    }

    public function test_dedicated_v1_can_read_legacy_unbound_intents_but_shared_v2_cannot(): void
    {
        $token = str_repeat('x', 64);
        DiscordActionIntent::query()->create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $this->actor->id,
            'discord_account_id' => $this->discordAccount->id,
            'discord_user_id' => self::DISCORD_USER_ID,
            'guild_id' => self::GUILD_ID,
            'connection_id' => null,
            'connection_generation' => null,
            'application_id' => null,
            'action' => 'legacy.action',
            'payload' => [],
            'status' => DiscordActionIntent::STATUS_DRAFT,
            'expires_at' => now()->addMinute(),
        ]);

        $legacy = $this->service->get(
            $this->actor,
            self::GUILD_ID,
            $token,
            'legacy.action',
            $this->dedicatedConnection(),
        );
        $this->assertSame(hash('sha256', $token), $legacy->token_hash);

        $this->expectException(ValidationException::class);
        $this->service->get(
            $this->actor,
            self::GUILD_ID,
            $token,
            'legacy.action',
            $this->sharedConnection(),
        );
    }

    private function sharedConnection(
        string $connectionId = '11111111-2222-4333-8444-555555555555',
        string $applicationId = self::APPLICATION_ID,
        string $guildId = self::GUILD_ID,
        int $generation = 7,
    ): DiscordConnectionContext {
        return new DiscordConnectionContext(
            connectionId: $connectionId,
            mode: DiscordConnectionMode::OfficialShared,
            applicationId: $applicationId,
            guildId: $guildId,
            generation: $generation,
            protocolVersion: 2,
            relayCurrentKeyId: 'relay-current',
            relayCurrentPublicKey: str_repeat('A', 43),
        );
    }

    private function dedicatedConnection(): DiscordConnectionContext
    {
        return new DiscordConnectionContext(
            connectionId: '99999999-8888-4777-8666-555555555555',
            mode: DiscordConnectionMode::Dedicated,
            applicationId: self::APPLICATION_ID,
            guildId: self::GUILD_ID,
            generation: 1,
            protocolVersion: 1,
            relayCurrentKeyId: 'legacy',
            relayCurrentPublicKey: '',
            persisted: false,
        );
    }
}
