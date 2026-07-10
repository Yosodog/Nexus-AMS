<?php

namespace App\Http\Controllers\API;

use App\Enums\DiscordQueueStatus;
use App\Exceptions\DiscordQueueLeaseException;
use App\Http\Controllers\Controller;
use App\Models\DiscordQueue;
use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiscordQueueController extends Controller
{
    public function __construct(private readonly DiscordQueueLeaseService $leaseService) {}

    /**
     * Transitional legacy batch claim endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $entries = DB::transaction(function () use ($limit) {
            $commands = DiscordQueue::query()
                ->available()
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $commands->each(function (DiscordQueue $command): void {
                $command->forceFill([
                    'status' => DiscordQueueStatus::Processing,
                    'attempts' => $command->attempts + 1,
                    'claim_request_id' => null,
                    'worker_id' => null,
                    'lease_token' => null,
                    'leased_until' => null,
                    'last_error' => null,
                ])->save();
            });

            return $commands;
        }, attempts: 3);

        return response()->json([
            'data' => $entries->map(fn (DiscordQueue $command): array => $this->commandData($command)),
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'],
        ]);

        try {
            $command = $this->leaseService->claim($data['worker_id'], $data['request_id']);
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
            $command = $this->leaseService->renew($command, $data['lease_token']);
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
        $data = $request->validate([
            'lease_token' => ['required', 'uuid'],
            'result' => ['required', 'array:discord_channel_id'],
            'result.discord_channel_id' => ['required', 'string', 'regex:/^\d{17,20}$/'],
        ]);

        try {
            $command = $this->leaseService->checkpoint($command, $data['lease_token'], $data['result']);
        } catch (DiscordQueueLeaseException $exception) {
            return $this->leaseError($exception);
        }

        return response()->json([
            'data' => $this->commandData($command),
        ]);
    }

    public function update(Request $request, DiscordQueue $command): JsonResponse
    {
        $data = $request->validate([
            'lease_token' => ['nullable', 'uuid'],
            'status' => ['required', Rule::in([
                DiscordQueueStatus::Complete->value,
                DiscordQueueStatus::Failed->value,
            ])],
            'error_code' => ['nullable', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $command = $this->leaseService->acknowledge(
                $command,
                DiscordQueueStatus::from($data['status']),
                $data['lease_token'] ?? null,
                $data['error_code'] ?? null,
                $data['error_message'] ?? null,
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
}
