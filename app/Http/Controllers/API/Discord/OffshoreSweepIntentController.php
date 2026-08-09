<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordOffshoreSweepConfirmRequest;
use App\Http\Requests\Discord\DiscordOffshoreSweepPreviewRequest;
use App\Models\DiscordAccount;
use App\Models\OffshoreTransfer;
use App\Models\User;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordOffshoreSweepException;
use App\Services\Discord\DiscordOffshoreSweepIntentService;
use App\Services\Discord\DiscordWorkflowIntentService;
use Illuminate\Http\JsonResponse;

final class OffshoreSweepIntentController extends Controller
{
    use DiscordApiResponses;

    public function preview(
        DiscordOffshoreSweepPreviewRequest $request,
        DiscordOffshoreSweepIntentService $sweeps,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        [$actor, $account, $connection] = $this->context($request);
        try {
            $preview = $sweeps->preview($actor, $request->input('note'));
            $intent = $preview['sweep_required'] ? $intents->create(
                $actor,
                $account,
                $connection->guildId,
                $this->interactionId($request),
                DiscordOffshoreSweepIntentService::INTENT_ACTION,
                [
                    'offshore_id' => $preview['offshore']['id'],
                    'resource_version' => $preview['resource_version'],
                    'transfer_request_id' => $preview['transfer_request_id'],
                    'note' => $preview['note'],
                ],
                $connection,
            ) : null;
        } catch (DiscordOffshoreSweepException $exception) {
            return $this->sweepError($request, $connection, $exception);
        }

        return $this->discordData([
            'sweep_required' => $preview['sweep_required'],
            'intent' => $intent ? [
                'id' => $intent->presentedToken,
                'action' => $intent->action,
                'expires_at' => $intent->expires_at->toIso8601String(),
            ] : null,
            'summary' => [
                'title' => $preview['sweep_required'] ? 'Sweep the main bank?' : 'Main bank already empty',
                'description' => $preview['sweep_required']
                    ? 'Confirm to transfer the exact refreshed balances below into the primary offshore.'
                    : 'Nexus found no positive main-bank balances to sweep.',
                'offshore' => $preview['offshore'],
                'resources' => $preview['resources'],
                'note' => $preview['note'],
            ],
            'warnings' => $preview['warnings'],
            'resource_version' => $preview['resource_version'],
        ], 201, $this->metadata($request, $connection));
    }

    public function confirm(
        DiscordOffshoreSweepConfirmRequest $request,
        DiscordOffshoreSweepIntentService $sweeps,
        DiscordWorkflowIntentService $intents,
    ): JsonResponse {
        [$actor, , $connection] = $this->context($request);
        $executed = false;
        try {
            $transfer = $intents->consume(
                $actor,
                $connection->guildId,
                $request->string('intent_id')->toString(),
                DiscordOffshoreSweepIntentService::INTENT_ACTION,
                function (array $payload) use ($sweeps, $actor, &$executed): OffshoreTransfer {
                    $transfer = $sweeps->confirm($actor, $payload);
                    $executed = true;

                    return $transfer;
                },
                $connection,
            );
        } catch (DiscordOffshoreSweepException $exception) {
            return $this->sweepError($request, $connection, $exception);
        }

        $transfer->loadMissing('destinationOffshore');
        $reconciliation = $transfer->status === OffshoreTransfer::STATUS_RECONCILIATION_REQUIRED;
        $data = [
            'swept' => ! $reconciliation,
            'reconciliation_required' => $reconciliation,
            'message' => $reconciliation
                ? 'The bank response was ambiguous. Nexus recorded the transfer for manual reconciliation.'
                : 'Main bank sweep completed.',
            'offshore' => [
                'id' => $transfer->destinationOffshore?->id,
                'name' => $transfer->destinationOffshore?->name,
                'alliance_id' => $transfer->destinationOffshore?->alliance_id,
            ],
            'transfer' => [
                'id' => $transfer->id,
                'status' => $transfer->status,
                'payload' => $transfer->payload,
                'completed_at' => $transfer->completed_at?->toIso8601String(),
            ],
        ];

        return $this->discordData(
            $data,
            201,
            $this->metadata($request, $connection, idempotentReplay: ! $executed),
        );
    }

    /** @return array{User, DiscordAccount, DiscordConnectionContext} */
    private function context(DiscordOffshoreSweepPreviewRequest|DiscordOffshoreSweepConfirmRequest $request): array
    {
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
        $account = $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($actor instanceof User, 503, 'Discord actor context is unavailable.');
        abort_unless($account instanceof DiscordAccount, 503, 'Discord account context is unavailable.');
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return [$actor, $account, $connection];
    }

    private function sweepError(
        DiscordOffshoreSweepPreviewRequest|DiscordOffshoreSweepConfirmRequest $request,
        DiscordConnectionContext $connection,
        DiscordOffshoreSweepException $exception,
    ): JsonResponse {
        return $this->discordError(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->status,
            array_filter(['retryable' => false, 'user_action' => $exception->userAction], fn (mixed $value): bool => $value !== null),
            $this->metadata($request, $connection),
        );
    }

    private function interactionId(DiscordOffshoreSweepPreviewRequest|DiscordOffshoreSweepConfirmRequest $request): string
    {
        return (string) $request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE);
    }

    /** @return array<string, mixed> */
    private function metadata(
        DiscordOffshoreSweepPreviewRequest|DiscordOffshoreSweepConfirmRequest $request,
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
