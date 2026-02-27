<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\DiscordWarCounterArchiveRequest;
use App\Http\Requests\Discord\DiscordWarCounterAttachChannelRequest;
use App\Models\DiscordAccount;
use App\Models\WarCounter;
use App\Services\War\CounterAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class WarCounterController extends Controller
{
    public function attachChannel(DiscordWarCounterAttachChannelRequest $request): JsonResponse
    {
        $counter = WarCounter::query()->findOrFail($request->integer('war_counter_id'));

        $counter->update([
            'discord_channel_id' => $request->string('discord_channel_id')->toString(),
        ]);

        return response()->json([
            'counter' => $counter->fresh()->toArray(),
        ]);
    }

    public function archive(
        DiscordWarCounterArchiveRequest $request,
        CounterAssignmentService $assignmentService
    ): JsonResponse {
        $moderatorDiscordId = $request->string('moderator_discord_id')->toString();
        $moderator = DiscordAccount::query()
            ->where('discord_id', $moderatorDiscordId)
            ->whereNull('unlinked_at')
            ->latest('linked_at')
            ->first()?->user;

        if (! $moderator) {
            return response()->json([
                'error' => 'moderator_not_found',
                'message' => 'Moderator account is not linked to Nexus.',
            ], 403);
        }

        if (! Gate::forUser($moderator)->allows('manage-war-room')) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'You do not have permission to manage war counters.',
            ], 403);
        }

        $counter = WarCounter::query()->findOrFail($request->integer('war_counter_id'));

        $alreadyArchived = $counter->status === 'archived';

        if (! $alreadyArchived) {
            $counter = $assignmentService->archive($counter);
        }

        return response()->json([
            'counter' => $counter->fresh()->toArray(),
            'archived' => true,
            'already_archived' => $alreadyArchived,
        ]);
    }
}
