<?php

namespace App\Http\Controllers\API;

use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Models\DiscordQueue;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscordQueueController extends Controller
{
    public function __construct(private readonly DiscordQueueLeaseService $leaseService) {}

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'],
            'lanes' => ['required', 'array', 'size:1'],
            'lanes.*' => ['required', Rule::enum(DiscordQueueLane::class)],
            'guild_id' => ['required', 'string', 'regex:/^\d{17,20}$/'],
            'connection_id' => ['required', 'uuid'],
            'application_id' => ['required', 'string', 'regex:/^\d{17,20}$/'],
            'generation' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);

        $connection = $this->connection($request);
        if ((int) $request->attributes->get(VerifyDiscordInteraction::PROTOCOL_ATTRIBUTE) !== 2
            || $data['connection_id'] !== $connection->connectionId
            || $data['application_id'] !== $connection->applicationId
            || (int) $data['generation'] !== $connection->generation
            || $data['guild_id'] !== $connection->guildId
        ) {
            return response()->json([
                'error' => 'discord_connection_binding_mismatch',
                'message' => 'The queue claim does not match the verified Discord connection.',
            ], 403);
        }

        try {
            $command = $this->leaseService->claim(
                $data['worker_id'],
                $data['request_id'],
                array_map(
                    fn (string $lane): DiscordQueueLane => DiscordQueueLane::from($lane),
                    $data['lanes'],
                ),
                $connection,
            );
        } catch (DiscordQueueLeaseException $exception) {
            return $this->leaseError($exception);
        }

        return response()->json([
            'data' => $command ? $this->commandData($command) : null,
        ]);
    }

    public function lease(Request $request, DiscordQueue $command): JsonResponse
    {
        $data = $request->validate([
            'lease_token' => ['required', 'uuid'],
        ]);

        try {
            $command = $this->leaseService->renew($command, $data['lease_token'], $this->connection($request));
        } catch (DiscordQueueLeaseException $exception) {
            return $this->leaseError($exception);
        }

        return response()->json([
            'data' => [
                'id' => $command->id,
                'lease_token' => $command->lease_token,
                'leased_until' => optional($command->leased_until)->toIso8601String(),
            ],
        ]);
    }

    public function checkpoint(Request $request, DiscordQueue $command): JsonResponse
    {
        $rules = match ($command->action) {
            'WAR_ROOM_CREATE' => [
                'lease_token' => ['required', 'uuid'],
                'result' => ['required', 'array:discord_channel_id'],
                'result.discord_channel_id' => ['required', 'string', 'regex:/^\d{17,20}$/'],
            ],
            'CITY_TIER_SYNC' => [
                'lease_token' => ['required', 'uuid'],
                'result' => ['required', 'array:roles'],
                'result.roles' => ['required', 'array', 'max:250'],
                'result.roles.*' => ['required', 'array:bucket_start,bucket_end,discord_role_id'],
                'result.roles.*.bucket_start' => ['required', 'integer', 'min:1'],
                'result.roles.*.bucket_end' => ['required', 'integer', 'gte:result.roles.*.bucket_start'],
                'result.roles.*.discord_role_id' => ['required', 'string', 'regex:/^\d{17,20}$/'],
            ],
            'APPLICATION_DISCORD_RECONCILE' => [
                'lease_token' => ['required', 'uuid'],
                'result' => ['required', 'array:application_reconcile'],
                'result.application_reconcile' => ['required', 'array:application_revision,channel_id,channel_deleted,roles_added,roles_removed,intro_messages,notifications'],
                'result.application_reconcile.application_revision' => ['required', 'integer', 'min:1'],
                'result.application_reconcile.channel_id' => ['present', 'nullable', 'string', 'regex:/^\d{17,20}$/'],
                'result.application_reconcile.channel_deleted' => ['required', 'boolean'],
                'result.application_reconcile.roles_added' => ['required', 'array', 'max:100'],
                'result.application_reconcile.roles_added.*' => ['required', 'string', 'distinct', 'regex:/^\d{17,20}$/'],
                'result.application_reconcile.roles_removed' => ['required', 'array', 'max:100'],
                'result.application_reconcile.roles_removed.*' => ['required', 'string', 'distinct', 'regex:/^\d{17,20}$/'],
                'result.application_reconcile.intro_messages' => ['required', 'array', 'max:25'],
                'result.application_reconcile.intro_messages.*' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9._:-]{0,127}$/'],
                'result.application_reconcile.notifications' => ['required', 'array', 'max:25'],
                'result.application_reconcile.notifications.*' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9._:-]{0,127}$/'],
            ],
            default => [
                'lease_token' => ['required', 'uuid'],
                'result' => ['required', 'array'],
            ],
        };

        $data = $request->validate($rules);

        try {
            $command = $this->leaseService->checkpoint(
                $command,
                $data['lease_token'],
                $data['result'],
                $this->connection($request),
            );
        } catch (DiscordQueueLeaseException $exception) {
            return $this->leaseError($exception);
        }

        return response()->json([
            'data' => $this->commandData($command),
        ]);
    }

    public function update(Request $request, DiscordQueue $command): JsonResponse
    {
        $rules = [
            'lease_token' => ['required', 'uuid'],
            'status' => ['required', Rule::in([
                DiscordQueueStatus::Complete->value,
                DiscordQueueStatus::Failed->value,
            ])],
            'error_code' => ['nullable', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'result' => ['nullable', 'array', 'max:25'],
            'result.delivery' => ['nullable', Rule::in(['delivered', 'undeliverable', 'failed', 'quarantined'])],
            'result.retryable' => ['nullable', 'boolean'],
            'result.retry_after_ms' => ['nullable', 'integer', 'min:0', 'max:1800000'],
            'result.error_code' => ['nullable', 'string', 'max:100'],
            'result.error_message' => ['nullable', 'string', 'max:2000'],
            'result.provider_message_id' => ['nullable', 'string', 'max:32'],
            'result.guild_id' => ['nullable', 'string', 'max:32'],
            'result.channel_id' => ['nullable', 'string', 'max:32'],
        ];
        if ($command->action === 'ALERT_DELIVERY_V1') {
            $rules['result'] = ['required', 'array', 'max:25'];
            $rules['result.success'] = ['required', 'boolean'];
            $rules['result.delivery_id'] = ['required', 'string', 'max:120'];
            $rules['result.delivery'] = ['required', Rule::in(['delivered', 'undeliverable', 'failed', 'quarantined'])];
            $rules['result.retryable'] = ['required', 'boolean'];
        }

        $data = $request->validate($rules);

        try {
            $command = $this->leaseService->acknowledge(
                $command,
                DiscordQueueStatus::from($data['status']),
                $data['lease_token'] ?? null,
                $data['error_code'] ?? null,
                $data['error_message'] ?? null,
                $this->connection($request),
                $data['result'] ?? null,
            );
        } catch (DiscordQueueLeaseException $exception) {
            return $this->leaseError($exception);
        }

        return response()->json([
            'data' => [
                'id' => $command->id,
                'status' => $command->status,
                'available_at' => optional($command->available_at)->toIso8601String(),
                'attempts' => $command->attempts,
                'completed_at' => optional($command->completed_at)->toIso8601String(),
                'result' => $command->result ?? (object) [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function commandData(DiscordQueue $command): array
    {
        return [
            'id' => $command->id,
            'action' => $command->action,
            'connection_id' => $command->connection_id,
            'application_id' => $command->application_id,
            'generation' => $command->connection_generation,
            'lane' => $command->lane,
            'priority' => $command->priority,
            'guild_id' => $command->guild_id,
            'alert_delivery_batch_id' => $command->alert_delivery_batch_id,
            'dedupe_key' => $command->dedupe_key,
            'payload' => $command->payload,
            'status' => $command->status,
            'attempts' => $command->attempts,
            'lease_token' => $command->lease_token,
            'leased_until' => optional($command->leased_until)->toIso8601String(),
            'result' => $command->result ?? (object) [],
            'available_at' => optional($command->available_at)->toIso8601String(),
            'created_at' => optional($command->created_at)->toIso8601String(),
        ];
    }

    private function leaseError(DiscordQueueLeaseException $exception): JsonResponse
    {
        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
        ], $exception->status);
    }

    private function connection(Request $request): DiscordConnectionContext
    {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);

        if (! $connection instanceof DiscordConnectionContext) {
            throw new \LogicException('A verified relay-v2 connection is required for Discord queue access.');
        }

        return $connection;
    }
}
