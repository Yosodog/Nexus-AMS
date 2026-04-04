<?php

namespace Tests\Unit\Services;

use App\Models\DiscordAccount;
use App\Models\User;
use App\Services\DiscordAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

class DiscordAccountServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_get_or_create_verification_token_returns_existing_token_without_rotation(): void
    {
        $user = User::factory()->create([
            'discord_verification_token' => 'existing-token',
        ]);

        $token = DiscordAccountService::getOrCreateVerificationToken($user->fresh());

        $this->assertSame('existing-token', $token);
        $this->assertSame('existing-token', $user->fresh()->discord_verification_token);
    }

    public function test_verify_with_token_returns_null_for_unknown_tokens(): void
    {
        $this->assertNull(DiscordAccountService::verifyWithToken('missing-token', '123456', 'codex'));
    }

    public function test_verify_with_token_closes_old_links_and_clears_the_user_token(): void
    {
        $user = User::factory()->create([
            'discord_verification_token' => 'verify-me',
        ]);
        $otherUser = User::factory()->create([
            'nation_id' => 900002,
        ]);

        $oldUserLink = DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => 'legacy-discord',
            'linked_at' => now()->subDay(),
        ]);
        $sharedDiscordLink = DiscordAccount::factory()->create([
            'user_id' => $otherUser->id,
            'discord_id' => 'shared-discord',
        ]);

        $linkedAccount = DiscordAccountService::verifyWithToken('verify-me', 'shared-discord', str_repeat('x', 300));

        $this->assertNotNull($linkedAccount);
        $this->assertSame($user->id, $linkedAccount->user_id);
        $this->assertSame('shared-discord', $linkedAccount->discord_id);
        $this->assertSame(255, strlen($linkedAccount->discord_username));
        $this->assertNull($user->fresh()->discord_verification_token);
        $this->assertNotNull($oldUserLink->fresh()->unlinked_at);
        $this->assertNotNull($sharedDiscordLink->fresh()->unlinked_at);
    }

    public function test_unlink_user_marks_active_account_unlinked_and_clears_token_when_requested(): void
    {
        $user = User::factory()->create([
            'discord_verification_token' => 'stale-token',
        ]);
        $account = DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => 'shared-discord',
        ]);

        $unlinked = DiscordAccountService::unlinkUser($user, false);

        $this->assertNotNull($unlinked);
        $this->assertSame($account->id, $unlinked->id);
        $this->assertNotNull($account->fresh()->unlinked_at);
        $this->assertNull($user->fresh()->discord_verification_token);
    }
}
