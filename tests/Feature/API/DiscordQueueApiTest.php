<?php

namespace Tests\Feature\API;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\WarCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DiscordQueueApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.discord_bot_key', 'discord-test-token');
        Carbon::setTestNow('2026-07-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_claim_is_atomic_increments_attempts_once_and_replays_an_active_request(): void
    {
        $command = $this->createCommand();
        $workerId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        $first = $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/queue/claim', [
            'worker_id' => $workerId,
            'request_id' => $requestId,
        ]);

        $first->assertOk()
            ->assertJsonPath('data.id', $command->id)
            ->assertJsonPath('data.status', DiscordQueueStatus::Processing->value)
            ->assertJsonPath('data.attempts', 1)
            ->assertJsonPath('data.result', []);

        $leaseToken = $first->json('data.lease_token');
        $this->assertNotEmpty($leaseToken);

        $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/queue/claim', [
            'worker_id' => $workerId,
            'request_id' => $requestId,
        ])->assertOk()
            ->assertJsonPath('data.id', $command->id)
            ->assertJsonPath('data.lease_token', $leaseToken)
            ->assertJsonPath('data.attempts', 1);

        $command->refresh();
        $this->assertSame(1, $command->attempts);
        $this->assertSame($requestId, $command->claim_request_id);
        $this->assertSame($workerId, $command->worker_id);
    }

    public function test_claim_returns_null_only_when_no_work_is_available(): void
    {
        $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/queue/claim', [
            'worker_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data', null);
    }

    public function test_distinct_claim_requests_receive_distinct_available_commands(): void
    {
        $firstCommand = $this->createCommand(attributes: ['available_at' => Carbon::now()->subSeconds(2)]);
        $secondCommand = $this->createCommand(attributes: ['available_at' => Carbon::now()->subSecond()]);

        $firstClaim = $this->claimOne();
        $secondClaim = $this->claimOne();

        $this->assertSame($firstCommand->id, $firstClaim->json('data.id'));
        $this->assertSame($secondCommand->id, $secondClaim->json('data.id'));
        $this->assertNotSame($firstClaim->json('data.lease_token'), $secondClaim->json('data.lease_token'));
        $this->assertSame(2, DiscordQueue::query()
            ->where('status', DiscordQueueStatus::Processing->value)
            ->count());
    }

    public function test_reusing_an_inactive_claim_request_returns_conflict(): void
    {
        $this->createCommand();
        $workerId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        $claim = $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/queue/claim', [
            'worker_id' => $workerId,
            'request_id' => $requestId,
        ]);

        $this->withHeaders($this->discordHeaders())->postJson(
            '/api/v1/discord/queue/'.$claim->json('data.id').'/status',
            [
                'lease_token' => $claim->json('data.lease_token'),
                'status' => DiscordQueueStatus::Complete->value,
            ],
        )->assertOk();

        $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/queue/claim', [
            'worker_id' => $workerId,
            'request_id' => $requestId,
        ])->assertConflict()->assertJsonPath('error', 'claim_request_conflict');
    }

    public function test_lease_renewal_and_war_room_checkpoint_require_the_active_token(): void
    {
        $this->createCommand('WAR_ROOM_CREATE');
        $claim = $this->claimOne();
        $id = $claim->json('data.id');
        $token = $claim->json('data.lease_token');
        $originalExpiry = $claim->json('data.leased_until');

        Carbon::setTestNow(Carbon::now()->addMinute());

        $this->withHeaders($this->discordHeaders())->postJson("/api/v1/discord/queue/{$id}/lease", [
            'lease_token' => $token,
        ])->assertOk()
            ->assertJsonPath('data.lease_token', $token)
            ->assertJsonPath('data.leased_until', Carbon::now()->addMinutes(5)->toIso8601String());

        $this->assertNotSame($originalExpiry, DiscordQueue::query()->findOrFail($id)->leased_until?->toIso8601String());

        $this->withHeaders($this->discordHeaders())->patchJson("/api/v1/discord/queue/{$id}/checkpoint", [
            'lease_token' => $token,
            'result' => ['discord_channel_id' => '123456789012345678'],
        ])->assertOk()
            ->assertJsonPath('data.result.discord_channel_id', '123456789012345678');

        $this->withHeaders($this->discordHeaders())->patchJson("/api/v1/discord/queue/{$id}/checkpoint", [
            'lease_token' => (string) Str::uuid(),
            'result' => ['discord_channel_id' => '123456789012345679'],
        ])->assertConflict()->assertJsonPath('error', 'lease_conflict');
    }

    public function test_checkpoint_rejects_unsupported_actions_and_fields(): void
    {
        $this->createCommand('BEIGE_ALERT');
        $claim = $this->claimOne();

        $this->withHeaders($this->discordHeaders())->patchJson(
            '/api/v1/discord/queue/'.$claim->json('data.id').'/checkpoint',
            [
                'lease_token' => $claim->json('data.lease_token'),
                'result' => ['discord_channel_id' => '123456789012345678'],
            ],
        )->assertUnprocessable()->assertJsonPath('error', 'checkpoint_not_supported');
    }

    public function test_failed_acknowledgements_back_off_without_double_counting_attempts_and_stop_at_three(): void
    {
        $command = $this->createCommand();

        foreach ([1, 2, 3] as $attempt) {
            $claim = $this->claimOne();

            $claim->assertJsonPath('data.attempts', $attempt);

            $response = $this->withHeaders($this->discordHeaders())->postJson(
                "/api/v1/discord/queue/{$command->id}/status",
                [
                    'lease_token' => $claim->json('data.lease_token'),
                    'status' => DiscordQueueStatus::Failed->value,
                    'error_code' => 'discord_error',
                    'error_message' => "Attempt {$attempt} failed",
                ],
            );

            $response->assertOk()
                ->assertJsonPath('data.attempts', $attempt)
                ->assertJsonPath(
                    'data.status',
                    $attempt < 3 ? DiscordQueueStatus::Pending->value : DiscordQueueStatus::Failed->value,
                );

            $this->withHeaders($this->discordHeaders())->postJson(
                "/api/v1/discord/queue/{$command->id}/status",
                [
                    'lease_token' => $claim->json('data.lease_token'),
                    'status' => DiscordQueueStatus::Failed->value,
                    'error_code' => 'discord_error',
                    'error_message' => "Attempt {$attempt} failed",
                ],
            )->assertOk()
                ->assertJsonPath('data.attempts', $attempt);

            $command->refresh();
            $this->assertSame($attempt, $command->attempts);

            if ($attempt < 3) {
                Carbon::setTestNow(Carbon::now()->addMinutes($attempt));
            }
        }

        $this->assertSame(DiscordQueueStatus::Failed, $command->fresh()->status);
        $this->assertSame('discord_error', $command->fresh()->last_error['code']);
    }

    public function test_completed_acknowledgement_is_idempotent_after_response_loss_while_the_lease_is_cleared(): void
    {
        $this->createCommand();
        $claim = $this->claimOne();
        $id = $claim->json('data.id');
        $token = $claim->json('data.lease_token');
        $payload = [
            'lease_token' => $token,
            'status' => DiscordQueueStatus::Complete->value,
        ];

        $this->withHeaders($this->discordHeaders())
            ->postJson("/api/v1/discord/queue/{$id}/status", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', DiscordQueueStatus::Complete->value);

        $completed = DiscordQueue::query()->findOrFail($id);
        $this->assertNull($completed->leased_until);
        $this->assertNull($completed->worker_id);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame($token, $completed->lease_token);

        $this->withHeaders($this->discordHeaders())
            ->postJson("/api/v1/discord/queue/{$id}/status", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', DiscordQueueStatus::Complete->value);
    }

    public function test_expired_leases_are_reaped_but_legacy_processing_rows_are_untouched(): void
    {
        $expired = $this->createCommand(attributes: [
            'status' => DiscordQueueStatus::Processing,
            'attempts' => 1,
            'lease_token' => (string) Str::uuid(),
            'leased_until' => Carbon::now()->subSecond(),
            'worker_id' => (string) Str::uuid(),
            'claim_request_id' => (string) Str::uuid(),
        ]);
        $legacy = $this->createCommand(attributes: [
            'status' => DiscordQueueStatus::Processing,
            'attempts' => 1,
        ]);

        $this->artisan('discord-queue:reap-leases')
            ->expectsOutput('Reaped 1 expired Discord queue lease(s).')
            ->assertSuccessful();

        $this->assertSame(DiscordQueueStatus::Pending, $expired->fresh()->status);
        $this->assertSame('lease_expired', $expired->fresh()->last_error['code']);
        $this->assertSame(DiscordQueueStatus::Processing, $legacy->fresh()->status);
    }

    public function test_expired_lease_token_cannot_be_renewed_or_acknowledged(): void
    {
        $command = $this->createCommand(attributes: [
            'status' => DiscordQueueStatus::Processing,
            'attempts' => 1,
            'lease_token' => (string) Str::uuid(),
            'leased_until' => Carbon::now()->subSecond(),
            'worker_id' => (string) Str::uuid(),
            'claim_request_id' => (string) Str::uuid(),
        ]);

        $this->withHeaders($this->discordHeaders())
            ->postJson("/api/v1/discord/queue/{$command->id}/lease", [
                'lease_token' => $command->lease_token,
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'lease_conflict');

        $this->withHeaders($this->discordHeaders())
            ->postJson("/api/v1/discord/queue/{$command->id}/status", [
                'lease_token' => $command->lease_token,
                'status' => DiscordQueueStatus::Complete->value,
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'lease_conflict');

        $this->assertSame(DiscordQueueStatus::Processing, $command->fresh()->status);
    }

    public function test_third_expired_lease_becomes_terminally_failed(): void
    {
        $command = $this->createCommand(attributes: [
            'status' => DiscordQueueStatus::Processing,
            'attempts' => 3,
            'lease_token' => (string) Str::uuid(),
            'leased_until' => Carbon::now()->subSecond(),
        ]);

        $this->artisan('discord-queue:reap-leases')->assertSuccessful();

        $this->assertSame(DiscordQueueStatus::Failed, $command->fresh()->status);
        $this->assertSame(3, $command->fresh()->attempts);
    }

    public function test_legacy_claim_and_status_contract_remains_available_without_double_counting(): void
    {
        $command = $this->createCommand();

        $this->withHeaders($this->discordHeaders())
            ->getJson('/api/v1/discord/queue?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $command->id)
            ->assertJsonPath('data.0.attempts', 1)
            ->assertJsonPath('data.0.lease_token', null);

        $this->withHeaders($this->discordHeaders())
            ->postJson("/api/v1/discord/queue/{$command->id}/status", [
                'status' => DiscordQueueStatus::Failed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', DiscordQueueStatus::Pending->value)
            ->assertJsonPath('data.attempts', 1);
    }

    public function test_legacy_recovery_is_a_dry_run_unless_explicit_ids_are_selected(): void
    {
        $command = $this->createCommand(attributes: [
            'status' => DiscordQueueStatus::Processing,
            'attempts' => 1,
        ]);

        $this->artisan('discord-queue:recover-legacy')
            ->expectsOutputToContain('Dry run: 1 legacy processing command(s) found. No rows changed.')
            ->assertSuccessful();

        $this->assertSame(DiscordQueueStatus::Processing, $command->fresh()->status);

        $this->artisan('discord-queue:recover-legacy', [
            'ids' => [$command->id],
            '--requeue' => true,
        ])->expectsOutput('Requeued 1 explicitly selected legacy processing command(s).')
            ->assertSuccessful();

        $this->assertSame(DiscordQueueStatus::Pending, $command->fresh()->status);
        $this->assertSame('legacy_manual_requeue', $command->fresh()->last_error['code']);
    }

    public function test_bot_can_fetch_the_persisted_war_counter_channel(): void
    {
        $aggressor = Nation::factory()->create();
        $counter = WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'archived',
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
            'discord_channel_id' => '123456789012345678',
        ]);

        $this->withHeaders($this->discordHeaders())
            ->getJson("/api/v1/discord/war-counters/{$counter->id}")
            ->assertOk()
            ->assertJsonPath('counter.id', $counter->id)
            ->assertJsonPath('counter.discord_channel_id', '123456789012345678');
    }

    private function claimOne(): TestResponse
    {
        return $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/queue/claim', [
            'worker_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCommand(string $action = 'BEIGE_ALERT', array $attributes = []): DiscordQueue
    {
        return DiscordQueue::query()->create(array_merge([
            'action' => $action,
            'payload' => ['message' => 'Test queue command'],
            'status' => DiscordQueueStatus::Pending,
            'attempts' => 0,
            'available_at' => Carbon::now(),
        ], $attributes));
    }

    /**
     * @return array<string, string>
     */
    private function discordHeaders(): array
    {
        return [
            'Authorization' => 'Bearer discord-test-token',
            'Accept' => 'application/json',
        ];
    }
}
