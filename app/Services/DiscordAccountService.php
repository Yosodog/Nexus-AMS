<?php

namespace App\Services;

use App\Models\DiscordAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscordAccountService
{
    /**
     * Get the currently linked Discord account for a user.
     */
    public static function getActiveAccount(User $user): ?DiscordAccount
    {
        return $user->activeDiscordAccount();
    }

    /**
     * Return an existing verification token or create a fresh one when absent.
     */
    public static function getOrCreateVerificationToken(User $user): string
    {
        if (! empty($user->discord_verification_token)) {
            return $user->discord_verification_token;
        }

        return self::regenerateVerificationToken($user);
    }

    /**
     * Regenerate a Discord verification token for a user.
     */
    public static function regenerateVerificationToken(User $user): string
    {
        $token = self::generateUniqueToken();

        $user->discord_verification_token = $token;
        $user->save();

        return $token;
    }

    /**
     * Link a Discord account to a user using a verification token.
     *
     * @return DiscordAccount|null The newly linked Discord account or null when the token is invalid.
     */
    public static function verifyWithToken(string $token, string $discordId, string $discordUsername): ?DiscordAccount
    {
        $user = User::query()
            ->where('discord_verification_token', $token)
            ->first();

        if (! $user) {
            return null;
        }

        return DB::transaction(function () use ($user, $discordId, $discordUsername) {
            $now = now();

            self::closeActiveLinkForUser($user, $now);
            self::closeActiveLinksForDiscordId($discordId, $user->id, $now);

            $discordAccount = new DiscordAccount([
                'user_id' => $user->id,
                'discord_id' => $discordId,
                'discord_username' => mb_substr($discordUsername, 0, 255),
                'linked_at' => $now,
            ]);

            $discordAccount->save();

            $user->discord_verification_token = null;
            $user->save();

            return $discordAccount->fresh();
        });
    }

    /**
     * Unlink the active Discord account for a user.
     */
    public static function unlinkUser(User $user, bool $regenerateToken = true): ?DiscordAccount
    {
        $activeAccount = self::getActiveAccount($user);

        if ($activeAccount && is_null($activeAccount->unlinked_at)) {
            $activeAccount->unlinked_at = now();
            $activeAccount->save();
        }

        if ($regenerateToken) {
            self::regenerateVerificationToken($user);
        } else {
            $user->discord_verification_token = null;
            $user->save();
        }

        return $activeAccount?->fresh();
    }

    /**
     * Generate a unique verification token.
     */
    protected static function generateUniqueToken(): string
    {
        do {
            $token = (string) Str::uuid();
        } while (User::query()->where('discord_verification_token', $token)->exists());

        return $token;
    }

    /**
     * Close any active Discord link for the provided user.
     */
    protected static function closeActiveLinkForUser(User $user, CarbonInterface $timestamp): void
    {
        DiscordAccount::query()
            ->where('user_id', $user->id)
            ->whereNull('unlinked_at')
            ->update([
                'unlinked_at' => $timestamp,
            ]);
    }

    /**
     * Close any other active links that share the same Discord ID.
     */
    protected static function closeActiveLinksForDiscordId(string $discordId, int $currentUserId, CarbonInterface $timestamp): void
    {
        DiscordAccount::query()
            ->where('discord_id', $discordId)
            ->whereNull('unlinked_at')
            ->where('user_id', '!=', $currentUserId)
            ->update([
                'unlinked_at' => $timestamp,
            ]);
    }
}
