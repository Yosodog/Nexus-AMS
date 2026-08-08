<?php

namespace Tests\Unit\Services;

use App\Enums\DiscordActorContextState;
use App\Models\DiscordAccount;
use App\Models\User;
use App\Services\Discord\DiscordActorContextService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DiscordActorContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DISCORD_ID = '123456789012345678';

    private DiscordActorContextService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DiscordActorContextService::class);
        config()->set('app.name', 'Test Nexus');
        SettingService::setMfaRequiredForAllUsers(false);
        SettingService::setMfaRequiredForAdmins(false);
    }

    public function test_it_rejects_an_invalid_discord_actor_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->resolve('not-a-snowflake');
    }

    public function test_it_returns_a_safe_unlinked_state(): void
    {
        $context = $this->service->resolve(self::DISCORD_ID);

        $this->assertSame(DiscordActorContextState::Unlinked, $context->state);
        $this->assertFalse($context->isReady());
        $this->assertNull($context->actor);
        $this->assertSame('/login', $context->userAction['path']);
        $this->assertSame([
            'state' => 'unlinked',
            'message' => 'This Discord account is not linked to Test Nexus.',
            'user_action' => [
                'label' => 'Sign in to Test Nexus',
                'path' => '/login',
            ],
        ], $context->safePayload());
    }

    public function test_it_fails_closed_when_multiple_active_links_exist(): void
    {
        $this->link($this->user());
        $this->link($this->user());

        $context = $this->service->resolve(self::DISCORD_ID);

        $this->assertSame(DiscordActorContextState::Ambiguous, $context->state);
        $this->assertNull($context->actor);
        $this->assertNull($context->discordAccount);
        $this->assertNull($context->userAction);
    }

    public function test_it_distinguishes_disabled_unverified_and_missing_nation_states(): void
    {
        $disabled = $this->user(['disabled' => true]);
        $this->link($disabled);
        $this->assertSame(
            DiscordActorContextState::Disabled,
            $this->service->resolve(self::DISCORD_ID)->state,
        );

        DiscordAccount::query()->delete();
        $unverified = $this->user(['verified_at' => null]);
        $this->link($unverified);
        $this->assertSame(
            DiscordActorContextState::NexusUnverified,
            $this->service->resolve(self::DISCORD_ID)->state,
        );

        DiscordAccount::query()->delete();
        $withoutNation = $this->user(['nation_id' => 0]);
        $this->link($withoutNation);
        $this->assertSame(
            DiscordActorContextState::NoNation,
            $this->service->resolve(self::DISCORD_ID)->state,
        );
    }

    public function test_it_requires_mfa_when_nexus_policy_requires_it(): void
    {
        SettingService::setMfaRequiredForAllUsers(true);
        $actor = $this->user();
        $this->link($actor);

        $context = $this->service->resolve(self::DISCORD_ID);

        $this->assertSame(DiscordActorContextState::MfaRequired, $context->state);
        $this->assertSame($actor->id, $context->actor?->id);
        $this->assertSame('/user/settings', $context->userAction['path']);
    }

    public function test_it_returns_the_ready_actor_without_exposing_it_in_the_safe_payload(): void
    {
        $actor = $this->user();
        $account = $this->link($actor);

        $context = $this->service->resolve(self::DISCORD_ID);

        $this->assertSame(DiscordActorContextState::Ready, $context->state);
        $this->assertTrue($context->isReady());
        $this->assertSame($actor->id, $context->actor?->id);
        $this->assertSame($account->id, $context->discordAccount?->id);
        $this->assertSame('/user/dashboard', $context->userAction['path']);
        $this->assertArrayNotHasKey('actor', $context->safePayload());
        $this->assertArrayNotHasKey('discord_account', $context->safePayload());
        $this->assertStringNotContainsString($actor->email, json_encode($context->safePayload(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'nation_id' => fake()->unique()->numberBetween(1000, 999999),
            'verified_at' => now(),
        ], $overrides));
    }

    private function link(User $user): DiscordAccount
    {
        return DiscordAccount::query()->create([
            'user_id' => $user->id,
            'discord_id' => self::DISCORD_ID,
            'discord_username' => 'discord-user',
            'linked_at' => now(),
        ]);
    }
}
