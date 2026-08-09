<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordLinkConfirmRequest;
use App\Http\Requests\Discord\DiscordLinkPreviewRequest;
use App\Models\DiscordAccount;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordLinkException;
use App\Services\Discord\DiscordWorkflowIntentService;
use App\Services\DiscordAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscordVerificationController extends Controller
{
    use DiscordApiResponses;

    public function preview(
        DiscordLinkPreviewRequest $request,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        $connection = $this->connection($request);
        $discordUserId = $this->discordUserId($request);
        $user = DiscordAccountService::findUserByVerificationToken($request->string('token')->toString());

        if (! $user) {
            return $this->discordError(
                'verification_token_invalid',
                'This verification code is invalid or has already been used.',
                404,
                ['retryable' => false, 'user_action' => 'Copy a current code from your Nexus account settings.'],
                $this->metadata($request, $connection),
            );
        }
        if ($user->disabled) {
            return $this->discordError(
                'nexus_account_disabled',
                'This Nexus account is disabled and cannot be linked.',
                403,
                ['retryable' => false, 'user_action' => 'Contact a Nexus administrator.'],
                $this->metadata($request, $connection),
            );
        }

        $currentLink = DiscordAccount::query()
            ->where('discord_id', $discordUserId)
            ->whereNull('unlinked_at')
            ->first();
        $intent = $intents->createForDiscordUser(
            $discordUserId,
            $connection->guildId,
            $this->interactionId($request),
            'account.link',
            [
                'target_user_id' => $user->id,
                'verification_token_hash' => hash('sha256', $request->string('token')->toString()),
                'discord_username' => $request->string('discord_username')->toString(),
            ],
            $connection,
        );

        return $this->discordData([
            'intent' => [
                'id' => $intent->presentedToken,
                'action' => $intent->action,
                'expires_at' => $intent->expires_at->toIso8601String(),
            ],
            'summary' => [
                'title' => 'Link this Discord account to Nexus?',
                'description' => $user->nation
                    ? "Confirm to link this Discord account to nation #{$user->nation->id}."
                    : 'Confirm to link this Discord account to the Nexus account that issued the code.',
                'nation' => $user->nation ? array_filter([
                    'id' => $user->nation->id,
                    'name' => $user->nation->nation_name,
                    'leader_name' => $user->nation->leader_name,
                ], static fn (mixed $value): bool => $value !== null && $value !== '') : null,
                'replaces_existing_link' => $currentLink !== null
                    && (int) $currentLink->user_id !== (int) $user->id,
            ],
            'warnings' => $currentLink !== null && (int) $currentLink->user_id !== (int) $user->id
                ? ['This Discord account is currently linked elsewhere. Confirming will replace that active link.']
                : [],
        ], 201, $this->metadata($request, $connection));
    }

    public function confirm(
        DiscordLinkConfirmRequest $request,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        $connection = $this->connection($request);
        $discordUserId = $this->discordUserId($request);
        $intentId = $request->string('intent_id')->toString();
        $executed = false;

        try {
            $discordAccount = $intents->consumeForDiscordUser(
                $discordUserId,
                $connection->guildId,
                $intentId,
                'account.link',
                function (array $payload) use ($discordUserId, $connection, &$executed): DiscordAccount {
                    $account = DiscordAccountService::verifyWithTokenHash(
                        (int) $payload['target_user_id'],
                        (string) $payload['verification_token_hash'],
                        $discordUserId,
                        (string) $payload['discord_username'],
                        $connection,
                    );
                    if (! $account) {
                        throw new DiscordLinkException(
                            'verification_intent_stale',
                            'The verification code changed or was already used after this preview.',
                            409,
                        );
                    }
                    $executed = true;

                    return $account;
                },
                $connection,
            );
        } catch (DiscordLinkException $exception) {
            return $this->discordError(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                ['retryable' => false, 'user_action' => 'Get a fresh verification code from Nexus and run /verify again.'],
                $this->metadata($request, $connection),
            );
        }

        $discordAccount->loadMissing('user.nation:id,nation_name,leader_name');
        $nation = $discordAccount->user?->nation;

        return $this->discordData([
            'linked' => true,
            'discord_user_id' => (string) $discordAccount->discord_id,
            'discord_username' => $discordAccount->discord_username,
            'linked_at' => $discordAccount->linked_at?->toIso8601String(),
            'nation' => $nation ? array_filter([
                'id' => $nation->id,
                'name' => $nation->nation_name,
                'leader_name' => $nation->leader_name,
            ], static fn (mixed $value): bool => $value !== null && $value !== '') : null,
        ], 201, $this->metadata($request, $connection, idempotentReplay: ! $executed));
    }

    /**
     * Link a Discord account to a user by validating a verification token.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'discord_id' => ['required', 'string'],
            'discord_username' => ['required', 'string'],
        ]);

        $discordAccount = DiscordAccountService::verifyWithToken(
            $validated['token'],
            $validated['discord_id'],
            $validated['discord_username']
        );

        if (! $discordAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'user_id' => $discordAccount->user_id,
            'discord_id' => $discordAccount->discord_id,
            'discord_username' => $discordAccount->discord_username,
            'linked_at' => optional($discordAccount->linked_at)->toIso8601String(),
        ]);
    }

    private function connection(DiscordLinkPreviewRequest|DiscordLinkConfirmRequest $request): DiscordConnectionContext
    {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return $connection;
    }

    private function discordUserId(DiscordLinkPreviewRequest|DiscordLinkConfirmRequest $request): string
    {
        return (string) $request->attributes->get(VerifyDiscordInteraction::USER_ATTRIBUTE);
    }

    private function interactionId(DiscordLinkPreviewRequest|DiscordLinkConfirmRequest $request): string
    {
        return (string) $request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE);
    }

    /** @return array<string, mixed> */
    private function metadata(
        DiscordLinkPreviewRequest|DiscordLinkConfirmRequest $request,
        DiscordConnectionContext $connection,
        bool $idempotentReplay = false,
    ): array {
        return [
            'capability_revision' => $connection->capabilityVersion,
            'connection_id' => $connection->connectionId,
            'connection_generation' => $connection->generation,
            'discord_application_id' => $connection->applicationId,
            'guild_id' => $connection->guildId,
            'generated_at' => now()->toIso8601String(),
            'correlation_id' => $this->interactionId($request),
            'idempotent_replay' => $idempotentReplay,
        ];
    }
}
