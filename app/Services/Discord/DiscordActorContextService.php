<?php

namespace App\Services\Discord;

use App\Enums\DiscordActorContextState;
use App\Models\DiscordAccount;
use App\Services\SettingService;
use InvalidArgumentException;

final class DiscordActorContextService
{
    public function resolve(string $discordUserId): DiscordActorContext
    {
        $discordUserId = trim($discordUserId);

        if (preg_match('/^\d{17,20}$/', $discordUserId) !== 1) {
            throw new InvalidArgumentException('A valid Discord user ID is required.');
        }

        $appName = trim((string) config('app.name', 'Nexus')) ?: 'Nexus';
        $accounts = DiscordAccount::query()
            ->with('user')
            ->where('discord_id', $discordUserId)
            ->whereNull('unlinked_at')
            ->limit(2)
            ->get()
            ->values();

        if ($accounts->isEmpty()) {
            return new DiscordActorContext(
                DiscordActorContextState::Unlinked,
                "This Discord account is not linked to {$appName}.",
                userAction: [
                    'label' => "Sign in to {$appName}",
                    'path' => route('login', absolute: false),
                ],
            );
        }

        if ($accounts->count() !== 1) {
            return new DiscordActorContext(
                DiscordActorContextState::Ambiguous,
                "This Discord account has multiple active {$appName} links. Contact an administrator to remove the duplicate link.",
            );
        }

        $discordAccount = $accounts->first();
        $actor = $discordAccount->user;

        if (! $actor) {
            return new DiscordActorContext(
                DiscordActorContextState::Unlinked,
                "This Discord account is not linked to an available {$appName} user.",
                userAction: [
                    'label' => "Sign in to {$appName}",
                    'path' => route('login', absolute: false),
                ],
            );
        }

        if ($actor->disabled) {
            return new DiscordActorContext(
                DiscordActorContextState::Disabled,
                "The linked {$appName} account is disabled. Contact an administrator before trying again.",
                actor: $actor,
                discordAccount: $discordAccount,
            );
        }

        if (! $actor->isVerified()) {
            return new DiscordActorContext(
                DiscordActorContextState::NexusUnverified,
                "The linked {$appName} account has not completed nation verification.",
                actor: $actor,
                discordAccount: $discordAccount,
                userAction: [
                    'label' => 'Finish verification',
                    'path' => route('login', absolute: false),
                ],
            );
        }

        if (! $actor->nation_id) {
            return new DiscordActorContext(
                DiscordActorContextState::NoNation,
                "The linked {$appName} account does not have a nation assigned.",
                actor: $actor,
                discordAccount: $discordAccount,
                userAction: [
                    'label' => 'Review account settings',
                    'path' => route('user.settings', absolute: false),
                ],
            );
        }

        $requiresMfa = SettingService::isMfaRequiredForAllUsers()
            || ($actor->is_admin && SettingService::isMfaRequiredForAdmins());

        if ($requiresMfa && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return new DiscordActorContext(
                DiscordActorContextState::MfaRequired,
                "Multi-factor authentication must be configured in {$appName} before using Discord workflows.",
                actor: $actor,
                discordAccount: $discordAccount,
                userAction: [
                    'label' => 'Configure MFA',
                    'path' => route('user.settings', absolute: false),
                ],
            );
        }

        return new DiscordActorContext(
            DiscordActorContextState::Ready,
            "This Discord account is ready to use {$appName} workflows.",
            actor: $actor,
            discordAccount: $discordAccount,
            userAction: [
                'label' => "Open {$appName}",
                'path' => route('user.dashboard', absolute: false),
            ],
        );
    }
}
