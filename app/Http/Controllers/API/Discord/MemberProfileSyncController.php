<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordProfileSyncConfirmRequest;
use App\Http\Requests\Discord\DiscordProfileSyncPreviewRequest;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\User;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordMemberProfileSyncService;
use App\Services\Discord\DiscordProfileSyncException;
use App\Services\Discord\DiscordWorkflowIntentService;
use Illuminate\Http\JsonResponse;

final class MemberProfileSyncController extends Controller
{
    use DiscordApiResponses;

    public function preview(
        DiscordProfileSyncPreviewRequest $request,
        DiscordMemberProfileSyncService $profiles,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        [$actor, $discordAccount, $connection] = $this->context($request);

        try {
            $preview = $profiles->preview(
                $actor,
                $discordAccount,
                $connection,
                $request->input('observed.nickname'),
                $request->array('observed.role_ids'),
            );
            $intent = $intents->create(
                $actor,
                $discordAccount,
                $connection->guildId,
                $this->interactionId($request),
                DiscordMemberProfileSyncService::INTENT_ACTION,
                [
                    'resource_version' => $preview['resource_version'],
                    'plan_hash' => $preview['plan_hash'],
                    'observed' => $preview['observed'],
                ],
                $connection,
            );
        } catch (DiscordProfileSyncException $exception) {
            return $this->profileError($request, $connection, $exception);
        }

        return $this->discordData([
            'intent' => [
                'id' => $intent->presentedToken,
                'action' => $intent->action,
                'expires_at' => $intent->expires_at->toIso8601String(),
            ],
            'summary' => $preview['summary'],
            'warnings' => $preview['warnings'],
            'resource_version' => $preview['resource_version'],
        ], 201, $this->metadata($request, $connection));
    }

    public function confirm(
        DiscordProfileSyncConfirmRequest $request,
        DiscordMemberProfileSyncService $profiles,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        [$actor, $discordAccount, $connection] = $this->context($request);
        $intentId = $request->string('intent_id')->toString();
        $executed = false;

        try {
            $queue = $intents->consume(
                $actor,
                $connection->guildId,
                $intentId,
                DiscordMemberProfileSyncService::INTENT_ACTION,
                function (array $payload) use ($profiles, $actor, $discordAccount, $connection, &$executed): DiscordQueue {
                    $queue = $profiles->confirm($actor, $discordAccount, $connection, $payload);
                    $executed = true;

                    return $queue;
                },
                $connection,
            );
        } catch (DiscordProfileSyncException $exception) {
            return $this->profileError($request, $connection, $exception);
        }

        return $this->discordData([
            'queued' => true,
            'queue' => [
                'id' => $queue->getKey(),
                'status' => $queue->status->value,
                'created_at' => $queue->created_at->toIso8601String(),
            ],
            'profile_sync' => [
                'state' => 'pending',
                'label' => 'Discord profile synchronization is queued.',
                'checked_at' => $queue->created_at->toIso8601String(),
                'issues' => [],
            ],
        ], 201, $this->metadata($request, $connection, idempotentReplay: ! $executed));
    }

    /** @return array{User, DiscordAccount, DiscordConnectionContext} */
    private function context(DiscordProfileSyncPreviewRequest|DiscordProfileSyncConfirmRequest $request): array
    {
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
        $discordAccount = $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($actor instanceof User, 503, 'Discord actor context is unavailable.');
        abort_unless($discordAccount instanceof DiscordAccount, 503, 'Discord account context is unavailable.');
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return [$actor, $discordAccount, $connection];
    }

    private function profileError(
        DiscordProfileSyncPreviewRequest|DiscordProfileSyncConfirmRequest $request,
        DiscordConnectionContext $connection,
        DiscordProfileSyncException $exception,
    ): JsonResponse {
        return $this->discordError(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->status,
            array_filter([
                'retryable' => false,
                'user_action' => $exception->userAction,
            ], static fn (mixed $value): bool => $value !== null),
            $this->metadata($request, $connection),
        );
    }

    private function interactionId(
        DiscordProfileSyncPreviewRequest|DiscordProfileSyncConfirmRequest $request,
    ): string {
        return (string) $request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE);
    }

    /** @return array<string, mixed> */
    private function metadata(
        DiscordProfileSyncPreviewRequest|DiscordProfileSyncConfirmRequest $request,
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
