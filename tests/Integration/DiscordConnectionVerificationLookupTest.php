<?php

namespace Tests\Integration;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use App\Models\DiscordConnection;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordConnectionResolver;
use Illuminate\Support\Facades\DB;

class DiscordConnectionVerificationLookupTest extends MySqlIntegrationTestCase
{
    private const FIRST_ID = '11111111-2222-4333-8444-555555555555';

    private const SECOND_ID = '22222222-2222-4333-8444-555555555555';

    public function test_verification_resolves_the_requested_active_connection_in_one_query(): void
    {
        $this->createConnection(self::FIRST_ID, '323456789012345678');
        $this->createConnection(self::SECOND_ID, '423456789012345678');
        $resolver = app(DiscordConnectionResolver::class);
        $resolver->resolveForVerification(self::FIRST_ID);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = $resolver->resolveForVerification(self::SECOND_ID);
        $first = $resolver->resolveForVerification(self::FIRST_ID);

        $this->assertSame(self::SECOND_ID, $second->connectionId);
        $this->assertSame('423456789012345678', $second->guildId);
        $this->assertSame(self::FIRST_ID, $first->connectionId);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_unknown_and_revoked_connections_fail_closed(): void
    {
        $this->createConnection(self::FIRST_ID, '323456789012345678');
        $this->createConnection(self::SECOND_ID, '423456789012345678', DiscordConnectionState::Revoked);
        config([
            'services.discord.connection_id' => '99999999-2222-4333-8444-555555555555',
            'services.discord.guild_id' => '523456789012345678',
        ]);
        $resolver = app(DiscordConnectionResolver::class);

        foreach ([self::SECOND_ID, '99999999-2222-4333-8444-555555555555'] as $connectionId) {
            try {
                $resolver->resolveForVerification($connectionId);
                $this->fail('Only an active persisted connection may verify a relay proof.');
            } catch (DiscordConnectionResolutionException $exception) {
                $this->assertSame('discord_connection_not_active', $exception->errorCode);
            }
        }
    }

    private function createConnection(
        string $id,
        string $guildId,
        DiscordConnectionState $state = DiscordConnectionState::Active,
    ): void {
        DiscordConnection::query()->create([
            'id' => $id,
            'mode' => DiscordConnectionMode::Dedicated,
            'state' => $state,
            'application_id' => '123456789012345678',
            'guild_id' => $guildId,
            'generation' => 1,
            'protocol_version' => 2,
            'relay_current_key_id' => 'relay-current',
            'relay_current_public_key' => str_repeat('a', 43),
            'capability_version' => 1,
            'capabilities' => [],
            'v1_reader_enabled' => false,
            'activated_at' => now(),
            'revoked_at' => $state === DiscordConnectionState::Revoked ? now() : null,
        ]);
    }
}
