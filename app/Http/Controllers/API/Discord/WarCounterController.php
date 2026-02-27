<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\DiscordWarCounterArchiveRequest;
use App\Http\Requests\Discord\DiscordWarCounterAttachChannelRequest;
use App\Models\WarCounter;
use App\Services\War\CounterAssignmentService;
use Illuminate\Http\JsonResponse;

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
