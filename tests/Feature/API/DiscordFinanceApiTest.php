<?php

namespace Tests\Feature\API;

use App\Jobs\SendBank;
use App\Models\Account;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\DiscordCommandReceipt;
use App\Models\Nation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawLimit;
use App\Services\PWHelperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscordFinanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_USER_ID = '234567890123456789';

    private User $actor;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord_bot_key' => 'test-discord-bot-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'services.discord.finance_action_intent_ttl_seconds' => 120,
            'services.pw.api_key' => 'test-api-key',
        ]);

        Notification::fake();
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
        $nation = Nation::factory()->create();
        $this->actor = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_USER_ID,
            'unlinked_at' => null,
        ]);

        $this->account = new Account;
        $this->account->nation_id = $nation->id;
        $this->account->name = 'Primary';
        foreach (PWHelperService::resources() as $resource) {
            $this->account->{$resource} = '1000.00';
        }
        $this->account->save();
    }

    public function test_actor_is_derived_from_active_verified_discord_link_and_configured_guild(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/accounts')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.accounts.0.id', $this->account->id)
            ->assertJsonPath('data.accounts.0.resources.money', '1000.00');

        $this->withHeaders($this->headers(guildId: '999999999999999999'))
            ->getJson('/api/v1/discord/me/accounts')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'invalid_discord_guild');

        $this->actor->forceFill(['verified_at' => null])->save();

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/accounts')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_actor_not_linked');
    }

    public function test_deposit_mutation_is_replayed_by_interaction_id_and_rejects_actor_authority(): void
    {
        $headers = $this->headers('345678901234567890');
        $url = "/api/v1/discord/me/accounts/{$this->account->id}/deposit-requests";

        $first = $this->withHeaders($headers)->postJson($url);
        $replay = $this->withHeaders($headers)->postJson($url);

        $first->assertCreated()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('meta.idempotent_replay', false);
        $replay->assertCreated()
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.deposit_request.id', $first->json('data.deposit_request.id'));

        $this->assertDatabaseCount('deposit_requests', 1);
        $this->assertDatabaseCount('discord_command_receipts', 1);
        $this->assertSame(
            DiscordCommandReceipt::STATUS_COMPLETED,
            DiscordCommandReceipt::query()->firstOrFail()->status,
        );

        $this->withHeaders($this->headers('456789012345678901'))
            ->postJson($url, ['nation_id' => $this->actor->nation_id])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.nation_id.0', 'The nation id field is prohibited.');
    }

    public function test_interaction_id_cannot_be_reused_for_a_different_mutation(): void
    {
        $interactionId = '567890123456789012';

        $this->withHeaders($this->headers($interactionId))
            ->postJson("/api/v1/discord/me/accounts/{$this->account->id}/deposit-requests")
            ->assertCreated();

        $this->withHeaders($this->headers($interactionId))
            ->postJson('/api/v1/discord/me/withdrawals/drafts', $this->withdrawalPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'discord_interaction_conflict');
    }

    public function test_withdrawal_draft_uses_hashed_token_and_confirm_reuses_secure_explicit_actor_path(): void
    {
        $draft = $this->withHeaders($this->headers('678901234567890123'))
            ->postJson('/api/v1/discord/me/withdrawals/drafts', $this->withdrawalPayload('10.25'))
            ->assertCreated()
            ->assertJsonPath('data.withdrawal.resources.money', '10.25')
            ->assertJsonPath('data.withdrawal.status', DiscordActionIntent::STATUS_DRAFT);

        $token = (string) $draft->json('data.withdrawal.id');
        $this->assertSame(64, strlen($token));
        $intent = DiscordActionIntent::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $intent->token_hash);
        $this->assertNotSame($token, $intent->token_hash);
        $this->assertLessThanOrEqual(121, now()->diffInSeconds($intent->expires_at));

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/discord/me/withdrawals/{$token}")
            ->assertOk()
            ->assertJsonPath('data.withdrawal.id', $token);

        $headers = $this->headers('789012345678901234');
        $url = "/api/v1/discord/me/withdrawals/{$token}/confirm";
        $first = $this->withHeaders($headers)->postJson($url);
        $replay = $this->withHeaders($headers)->postJson($url);

        $first->assertOk()
            ->assertJsonPath('data.withdrawal.status', DiscordActionIntent::STATUS_CONFIRMED)
            ->assertJsonPath('data.transaction.resources.money', '10.25');
        $replay->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.transaction.id', $first->json('data.transaction.id'));

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('989.75', number_format((float) $this->account->fresh()->money, 2, '.', ''));
        $this->assertSame($intent->id, Transaction::query()->firstOrFail()->discord_action_intent_id);
        Queue::assertPushed(SendBank::class, 1);
    }

    public function test_withdrawal_intent_is_actor_scoped_and_cancelable(): void
    {
        $draft = $this->withHeaders($this->headers('890123456789012345'))
            ->postJson('/api/v1/discord/me/withdrawals/drafts', $this->withdrawalPayload())
            ->assertCreated();
        $token = (string) $draft->json('data.withdrawal.id');

        $otherNation = Nation::factory()->create();
        $other = User::factory()->verified()->create(['nation_id' => $otherNation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $other->id,
            'discord_id' => '901234567890123456',
        ]);

        $this->withHeaders($this->headers(discordUserId: '901234567890123456'))
            ->getJson("/api/v1/discord/me/withdrawals/{$token}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'withdrawal_intent_not_found');

        $this->withHeaders($this->headers('912345678901234567'))
            ->postJson("/api/v1/discord/me/withdrawals/{$token}/cancel")
            ->assertOk()
            ->assertJsonPath('data.withdrawal.status', DiscordActionIntent::STATUS_CANCELED);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_existing_withdrawal_limit_routes_discord_withdrawal_to_staff_review_without_dispatch(): void
    {
        WithdrawLimit::query()->updateOrCreate(
            ['resource' => 'money'],
            ['daily_limit' => '5.00'],
        );

        $draft = $this->withHeaders($this->headers('923456789012345678'))
            ->postJson('/api/v1/discord/me/withdrawals/drafts', $this->withdrawalPayload('10.00'))
            ->assertCreated();

        $this->withHeaders($this->headers('934567890123456789'))
            ->postJson('/api/v1/discord/me/withdrawals/'.$draft->json('data.withdrawal.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('data.transaction.requires_admin_approval', true);

        $transaction = Transaction::query()->firstOrFail();
        $this->assertTrue($transaction->is_pending);
        $this->assertStringContainsString('Exceeded daily limit for Money', (string) $transaction->pending_reason);
        Queue::assertNotPushed(SendBank::class);
    }

    private function headers(
        ?string $interactionId = null,
        string $guildId = self::GUILD_ID,
        string $discordUserId = self::DISCORD_USER_ID,
    ): array {
        return array_filter([
            'Authorization' => 'Bearer test-discord-bot-key',
            'X-Discord-Guild-ID' => $guildId,
            'X-Discord-User-ID' => $discordUserId,
            'X-Discord-Interaction-ID' => $interactionId,
        ]);
    }

    private function withdrawalPayload(string $money = '10.00'): array
    {
        $resources = collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => $resource === 'money' ? $money : '0.00'])
            ->all();

        return [
            'account_id' => $this->account->id,
            'resources' => $resources,
        ];
    }
}
