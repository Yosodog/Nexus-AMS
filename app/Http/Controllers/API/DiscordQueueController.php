<?php

namespace App\Http\Controllers\API;

use App\Enums\DiscordQueueStatus;
use App\Http\Controllers\Controller;
use App\Models\DiscordQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiscordQueueController extends Controller
{
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
                $command->fill([
                    'status' => DiscordQueueStatus::Processing,
                    'attempts' => $command->attempts + 1,
                ])->save();
            });

            return $commands;
        });

        return response()->json([
            'data' => $entries->map(function (DiscordQueue $command): array {
                return [
                    'id' => $command->id,
                    'action' => $command->action,
                    'payload' => $command->payload,
                    'status' => $command->status,
                    'attempts' => $command->attempts,
                    'available_at' => optional($command->available_at)->toIso8601String(),
                    'created_at' => optional($command->created_at)->toIso8601String(),
                ];
            }),
        ]);
    }

    public function update(Request $request, DiscordQueue $command): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([DiscordQueueStatus::Complete->value, DiscordQueueStatus::Failed->value])],
        ]);

        if ($data['status'] === DiscordQueueStatus::Complete->value) {
            $command->update([
                'status' => DiscordQueueStatus::Complete,
            ]);
        } else {
            $attempts = $command->attempts + 1;
            $nextDelayMinutes = $attempts <= 3 ? max(1, $attempts) : null;
            $status = $attempts > 3 ? DiscordQueueStatus::Failed : DiscordQueueStatus::Pending;
            $availableAt = $nextDelayMinutes ? now()->addMinutes($nextDelayMinutes) : $command->available_at;

            $command->update([
                'status' => $status,
                'attempts' => $attempts,
                'available_at' => $availableAt,
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $command->id,
                'status' => $command->status,
                'available_at' => optional($command->available_at)->toIso8601String(),
                'attempts' => $command->attempts,
            ],
        ]);
    }
}
