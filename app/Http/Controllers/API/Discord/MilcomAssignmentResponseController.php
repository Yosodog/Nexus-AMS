<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordMilcomAssignmentResponseConfirmRequest;
use App\Http\Requests\Discord\DiscordMilcomAssignmentResponsePreviewRequest;
use App\Http\Resources\Discord\MilcomAssignmentResource;
use App\Http\Resources\Discord\MilcomAssignmentResponseResource;
use App\Models\DiscordAccount;
use App\Models\MilcomAssignmentResponse;
use App\Models\User;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordMilcomAssignmentResponseException;
use App\Services\Discord\DiscordMilcomAssignmentResponseService;
use App\Services\Discord\DiscordWorkflowIntentService;
use Illuminate\Http\JsonResponse;
use LogicException;

final class MilcomAssignmentResponseController extends Controller
{
    use DiscordApiResponses;

    public function preview(
        DiscordMilcomAssignmentResponsePreviewRequest $request,
        int $assignment,
        DiscordMilcomAssignmentResponseService $responses,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        [$actor, $account, $connection] = $this->context($request);

        try {
            $preview = $responses->preview(
                $actor,
                $assignment,
                $request->responseValue(),
                $request->reason(),
            );
            $intent = $intents->create(
                $actor,
                $account,
                $connection->guildId,
                $this->interactionId($request),
                DiscordMilcomAssignmentResponseService::INTENT_ACTION,
                $responses->intentPayload($actor, $preview),
                $connection,
            );
        } catch (DiscordMilcomAssignmentResponseException $exception) {
            return $this->responseError($request, $connection, $exception);
        }

        return $this->discordData([
            'intent' => [
                'id' => $intent->presentedToken,
                'action' => $intent->action,
                'expires_at' => $intent->expires_at->toIso8601String(),
            ],
            'assignment' => (new MilcomAssignmentResource($preview['assignment']))->resolve($request),
            'proposed_response' => [
                'response' => $preview['response'],
                'reason' => $preview['reason'],
            ],
            'resource_version' => $preview['resource_version'],
        ], 201, $this->metadata($request, $connection));
    }

    public function confirm(
        DiscordMilcomAssignmentResponseConfirmRequest $request,
        int $assignment,
        DiscordMilcomAssignmentResponseService $responses,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        [$actor, , $connection] = $this->context($request);
        $executed = false;

        try {
            $result = $intents->consume(
                $actor,
                $connection->guildId,
                $request->intentId(),
                DiscordMilcomAssignmentResponseService::INTENT_ACTION,
                function (array $payload) use ($responses, $actor, $assignment, $request, &$executed): MilcomAssignmentResponse {
                    $response = $responses->confirm(
                        $actor,
                        $assignment,
                        $payload,
                        $this->interactionId($request),
                    );
                    $executed = true;

                    return $response;
                },
                $connection,
            );
        } catch (DiscordMilcomAssignmentResponseException $exception) {
            return $this->responseError($request, $connection, $exception);
        }

        if (! $result instanceof MilcomAssignmentResponse) {
            throw new LogicException('A Milcom-v2 response intent returned an unexpected result model.');
        }

        return $this->discordData(
            (new MilcomAssignmentResponseResource($result))->resolve($request),
            201,
            $this->metadata($request, $connection, idempotentReplay: ! $executed),
        );
    }

    /** @return array{User, DiscordAccount, DiscordConnectionContext} */
    private function context(
        DiscordMilcomAssignmentResponsePreviewRequest|DiscordMilcomAssignmentResponseConfirmRequest $request,
    ): array {
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
        $account = $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($actor instanceof User, 503, 'Discord actor context is unavailable.');
        abort_unless($account instanceof DiscordAccount, 503, 'Discord account context is unavailable.');
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return [$actor, $account, $connection];
    }

    private function responseError(
        DiscordMilcomAssignmentResponsePreviewRequest|DiscordMilcomAssignmentResponseConfirmRequest $request,
        DiscordConnectionContext $connection,
        DiscordMilcomAssignmentResponseException $exception,
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
        DiscordMilcomAssignmentResponsePreviewRequest|DiscordMilcomAssignmentResponseConfirmRequest $request,
    ): string {
        return (string) $request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE);
    }

    /** @return array<string, mixed> */
    private function metadata(
        DiscordMilcomAssignmentResponsePreviewRequest|DiscordMilcomAssignmentResponseConfirmRequest $request,
        DiscordConnectionContext $connection,
        bool $idempotentReplay = false,
    ): array {
        return [
            'provider' => 'nexus_milcom_v2',
            'projection_schema_version' => 1,
            'actor_scope' => 'actor_current_assignment',
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
