<?php

namespace Tests\Feature\API;

use App\Models\Account;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DiscordWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_ID = '234567890123456789';

    private User $actor;

    private Account $account;

    private Grants $grant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord_bot_key' => 'workflow-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'services.discord.workflow_action_intent_ttl_seconds' => 900,
        ]);
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);

        $nation = Nation::factory()->create(['alliance_id' => 777]);
        $this->actor = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);
        $this->account = new Account;
        $this->account->nation_id = $nation->id;
        $this->account->name = 'Primary';
        $this->account->save();
        $this->grant = new Grants;
        $this->grant->name = 'Discord Grant';
        $this->grant->slug = 'discord-grant';
        $this->grant->description = 'Workflow contract test.';
        $this->grant->validation_rules = [];
        $this->grant->is_enabled = true;
        $this->grant->is_one_time = false;
        $this->grant->save();
    }

    public function test_grant_preview_and_confirmation_use_hashed_actor_bound_intent_and_idempotency(): void
    {
        $preview = $this->withHeaders($this->headers('345678901234567890'))
            ->postJson('/api/v1/discord/me/grant-applications/preview', [
                'grant_id' => $this->grant->id,
                'account_id' => $this->account->id,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.intent.action', 'grant.application');

        $token = (string) $preview->json('data.intent.id');
        $this->assertSame(64, strlen($token));
        $intent = DiscordActionIntent::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $intent->token_hash);
        $this->assertDatabaseMissing('discord_action_intents', ['token_hash' => $token]);

        $headers = $this->headers('456789012345678901');
        $first = $this->withHeaders($headers)->postJson('/api/v1/discord/me/grant-applications/confirm', [
            'intent_id' => $token,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/v1/discord/me/grant-applications/confirm', [
            'intent_id' => $token,
        ])->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('grant_applications', 1);
        $this->assertSame(DiscordActionIntent::STATUS_CONFIRMED, $intent->fresh()->status);
    }

    public function test_workflow_validation_uses_versioned_error_envelope(): void
    {
        $this->withHeaders($this->headers('567890123456789012'))
            ->postJson('/api/v1/discord/me/grant-applications/preview', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonStructure(['error' => ['details' => ['grant_id', 'account_id']]]);
    }

    public function test_workflow_intent_cannot_be_confirmed_by_another_linked_actor(): void
    {
        $preview = $this->withHeaders($this->headers('678901234567890123'))
            ->postJson('/api/v1/discord/me/grant-applications/preview', [
                'grant_id' => $this->grant->id,
                'account_id' => $this->account->id,
            ])->assertCreated();

        $otherNation = Nation::factory()->create(['alliance_id' => 777]);
        $other = User::factory()->verified()->create(['nation_id' => $otherNation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $other->id,
            'discord_id' => '789012345678901234',
            'unlinked_at' => null,
        ]);

        $this->withHeaders($this->headers('890123456789012345', '789012345678901234'))
            ->postJson('/api/v1/discord/me/grant-applications/confirm', [
                'intent_id' => $preview->json('data.intent.id'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertSame(0, GrantApplication::query()->count());
    }

    /** @return array<string, string> */
    private function headers(string $interactionId, string $discordId = self::DISCORD_ID): array
    {
        return [
            'Authorization' => 'Bearer workflow-test-key',
            'X-Discord-Guild-ID' => self::GUILD_ID,
            'X-Discord-User-ID' => $discordId,
            'X-Discord-Interaction-ID' => $interactionId,
        ];
    }
}
