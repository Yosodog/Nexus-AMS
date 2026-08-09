<?php

namespace App\Http\Controllers\API\Discord;

use App\Exceptions\ApplicationException;
use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordApplicationApproveRequest;
use App\Http\Requests\Discord\DiscordApplicationConfirmRequest;
use App\Http\Requests\Discord\DiscordApplicationDenyRequest;
use App\Http\Requests\Discord\DiscordApplicationMessageRequest;
use App\Http\Requests\Discord\DiscordApplicationPreviewRequest;
use App\Http\Requests\Discord\DiscordApplicationStoreRequest;
use App\Http\Requests\Discord\DiscordAttachChannelRequest;
use App\Models\Application;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Services\ApplicationService;
use App\Services\Discord\ApplicationDiscordReconciliationService;
use App\Services\Discord\ApplicationDiscordStatusProjection;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordWorkflowIntentService;
use Illuminate\Http\JsonResponse;

class ApplicationController extends Controller
{
    use DiscordApiResponses;

    public function __construct(private readonly ApplicationService $applicationService) {}

    public function preview(
        DiscordApplicationPreviewRequest $request,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        $connection = $this->requiredConnection($request);
        if (! $connection->supportsQueueAction(ApplicationDiscordReconciliationService::ACTION)) {
            return $this->discordError(
                'application_reconciliation_unavailable',
                'This Discord installation cannot safely create applications yet.',
                409,
                [
                    'retryable' => false,
                    'user_action' => 'Ask a server administrator to update the Nexus Discord integration.',
                ],
                $this->metadata($request, $connection),
            );
        }

        $discordUserId = $this->discordUserId($request);

        try {
            $preview = $this->applicationService->previewApplicationFromDiscord(
                $request->integer('nation_id'),
                $discordUserId,
                $connection,
            );
        } catch (ApplicationException $exception) {
            return $this->workflowError($request, $connection, $exception);
        }

        $application = $preview['application'];
        $nation = $preview['nation'];
        $intent = $intents->createForDiscordUser(
            $discordUserId,
            $connection->guildId,
            $this->interactionId($request),
            'application.create',
            [
                'nation_id' => (int) $nation->id,
                'discord_username' => $request->string('discord_username')->toString(),
                'resource_version' => $preview['resource_version'],
                'continue_existing' => $application !== null,
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
                'title' => $application
                    ? 'Continue your Nexus application?'
                    : 'Submit your Nexus application?',
                'description' => $application
                    ? 'Nexus found your pending application. Confirm to continue its Discord setup.'
                    : 'Confirm to submit this nation for review and start private Discord setup.',
                'nation' => array_filter([
                    'id' => (int) $nation->id,
                    'name' => $nation->nation_name,
                    'leader_name' => $nation->leader_name,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                'continues_existing_application' => $application !== null,
            ],
            'warnings' => $preview['warnings'],
            'resource_version' => $preview['resource_version'],
            'deep_link_path' => $application
                ? route('apply.show', ['application' => $application], absolute: false)
                : null,
        ], 201, $this->metadata($request, $connection));
    }

    public function confirm(
        DiscordApplicationConfirmRequest $request,
        DiscordWorkflowIntentService $intents,
        ApplicationDiscordStatusProjection $statusProjection,
    ): JsonResponse {
        $connection = $this->requiredConnection($request);
        if (! $connection->supportsQueueAction(ApplicationDiscordReconciliationService::ACTION)) {
            return $this->discordError(
                'application_reconciliation_unavailable',
                'This Discord installation cannot safely create applications yet.',
                409,
                [
                    'retryable' => false,
                    'user_action' => 'Ask a server administrator to update the Nexus Discord integration.',
                ],
                $this->metadata($request, $connection),
            );
        }
        $discordUserId = $this->discordUserId($request);
        $intentId = $request->string('intent_id')->toString();
        $executed = false;

        try {
            $intent = $intents->getForDiscordUser(
                $discordUserId,
                $connection->guildId,
                $intentId,
                'application.create',
                $connection,
            );
            $application = $intents->consumeForDiscordUser(
                $discordUserId,
                $connection->guildId,
                $intentId,
                'application.create',
                function (array $payload) use ($discordUserId, $connection, &$executed): Application {
                    $executed = true;

                    return $this->applicationService->createApplicationFromDiscord(
                        (int) $payload['nation_id'],
                        $discordUserId,
                        (string) $payload['discord_username'],
                        $connection,
                        (string) $payload['resource_version'],
                    );
                },
                $connection,
            );
        } catch (ApplicationException $exception) {
            return $this->workflowError($request, $connection, $exception);
        }

        $queue = is_string($application->discord_reconcile_queue_id)
            ? DiscordQueue::query()->find($application->discord_reconcile_queue_id)
            : null;

        return $this->discordData([
            'application' => [
                'id' => $application->getKey(),
                'nation_id' => $application->nation_id,
                'status' => $application->status->value,
                'continues_existing_application' => (bool) ($intent->payload['continue_existing'] ?? false),
                'created_at' => $application->created_at->toIso8601String(),
                'updated_at' => $application->updated_at->toIso8601String(),
            ],
            ...$statusProjection->forMember($application, $queue),
            'deep_link_path' => route('apply.show', ['application' => $application], absolute: false),
        ], 201, $this->metadata($request, $connection, idempotentReplay: ! $executed));
    }

    public function store(DiscordApplicationStoreRequest $request): JsonResponse
    {
        try {
            $application = $this->applicationService->createApplicationFromDiscord(
                $request->integer('nation_id'),
                $request->string('discord_user_id')->toString(),
                $request->string('discord_username')->toString(),
                $this->connection($request),
            );
            $nation = $this->applicationService->getNation($application->nation_id);
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'application' => $application->toArray(),
            'nation' => $nation,
            'config' => $this->applicationService->getDiscordConfig(),
        ], 201);
    }

    public function attachChannel(DiscordAttachChannelRequest $request): JsonResponse
    {
        $application = Application::query()->findOrFail($request->integer('application_id'));

        try {
            $application = $this->applicationService->attachChannelToApplication(
                $application,
                $request->string('discord_channel_id')->toString()
            );
        } catch (ApplicationException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'application' => $application->toArray(),
        ]);
    }

    public function storeMessage(DiscordApplicationMessageRequest $request): JsonResponse
    {
        $message = $this->applicationService->logDiscordMessage($request->validated());

        if (! $message) {
            return response()->json(['logged' => false]);
        }

        return response()->json([
            'logged' => true,
            'message' => $message->toArray(),
        ]);
    }

    public function approve(DiscordApplicationApproveRequest $request): JsonResponse
    {
        $moderatorDiscordId = $this->authenticatedModeratorDiscordId($request);
        if ($moderatorDiscordId instanceof JsonResponse) {
            return $moderatorDiscordId;
        }

        try {
            $application = $this->applicationService->approveByDiscordUser(
                $request->string('applicant_discord_id')->toString(),
                $moderatorDiscordId,
                $request->string('approval_request_id')->toString(),
                $this->connection($request),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'status' => 'approved',
            'application' => $application->toArray(),
            'config' => $this->applicationService->getDiscordConfig(),
        ]);
    }

    public function deny(DiscordApplicationDenyRequest $request): JsonResponse
    {
        $moderatorDiscordId = $this->authenticatedModeratorDiscordId($request);
        if ($moderatorDiscordId instanceof JsonResponse) {
            return $moderatorDiscordId;
        }

        try {
            $application = $this->applicationService->denyByDiscordUser(
                $request->string('applicant_discord_id')->toString(),
                $moderatorDiscordId,
                $request->string('denial_request_id')->toString(),
                $this->connection($request),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'status' => 'denied',
            'application' => $application->toArray(),
            'config' => $this->applicationService->getDiscordConfig(),
        ]);
    }

    protected function errorResponse(ApplicationException $exception): JsonResponse
    {
        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
            'context' => $exception->context,
        ], $exception->status);
    }

    private function authenticatedModeratorDiscordId(DiscordApplicationApproveRequest|DiscordApplicationDenyRequest $request): string|JsonResponse
    {
        $account = $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
        $claimedDiscordId = $request->string('moderator_discord_id')->toString();

        if (! $account instanceof DiscordAccount || ! hash_equals((string) $account->discord_id, $claimedDiscordId)) {
            return response()->json([
                'error' => 'discord_actor_mismatch',
                'message' => 'The moderator does not match the signed Discord interaction.',
                'context' => [],
            ], 403);
        }

        return (string) $account->discord_id;
    }

    private function connection(
        DiscordApplicationStoreRequest|DiscordApplicationApproveRequest|DiscordApplicationDenyRequest $request,
    ): ?DiscordConnectionContext {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);

        return $connection instanceof DiscordConnectionContext ? $connection : null;
    }

    private function requiredConnection(
        DiscordApplicationPreviewRequest|DiscordApplicationConfirmRequest $request,
    ): DiscordConnectionContext {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return $connection;
    }

    private function discordUserId(
        DiscordApplicationPreviewRequest|DiscordApplicationConfirmRequest $request,
    ): string {
        return (string) $request->attributes->get(VerifyDiscordInteraction::USER_ATTRIBUTE);
    }

    private function interactionId(
        DiscordApplicationPreviewRequest|DiscordApplicationConfirmRequest $request,
    ): string {
        return (string) $request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE);
    }

    private function workflowError(
        DiscordApplicationPreviewRequest|DiscordApplicationConfirmRequest $request,
        DiscordConnectionContext $connection,
        ApplicationException $exception,
    ): JsonResponse {
        $retryable = $exception->status >= 500
            || in_array($exception->error, ['application_creation_in_progress'], true);
        $userAction = match ($exception->error) {
            'nation_not_in_our_alliance', 'nation_not_applicant' => 'Join the configured alliance as an applicant, then run /apply again.',
            'application_preview_stale' => 'Run /apply again to review the latest application details.',
            'pending_application_exists' => 'Continue the existing application that Nexus identifies, or contact application staff.',
            'system_disabled' => 'Contact application staff for the current application process.',
            default => $retryable
                ? 'Wait a moment and try again.'
                : 'Review the details or contact application staff.',
        };

        return $this->discordError(
            $exception->error,
            $exception->getMessage(),
            $exception->status,
            [
                ...$exception->context,
                'retryable' => $retryable,
                'user_action' => $userAction,
            ],
            $this->metadata($request, $connection),
        );
    }

    /** @return array<string, mixed> */
    private function metadata(
        DiscordApplicationPreviewRequest|DiscordApplicationConfirmRequest $request,
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
            'source_updated_at' => now()->toIso8601String(),
            'correlation_id' => $this->interactionId($request),
            'idempotent_replay' => $idempotentReplay,
        ];
    }
}
