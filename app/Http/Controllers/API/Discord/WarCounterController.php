<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordWarCounterArchiveRequest;
use App\Http\Requests\Discord\DiscordWarCounterAttachChannelRequest;
use App\Models\User;
use App\Models\WarCounter;
use App\Services\War\CounterAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WarCounterController extends Controller
{
    public function show(WarCounter $counter): JsonResponse
    {
        return response()->json([
            'counter' => $counter->toArray(),
        ]);
    }

    public function attachChannel(DiscordWarCounterAttachChannelRequest $request): JsonResponse
    {
        if ($request->attributes->get(VerifyDiscordInteraction::SERVICE_ATTRIBUTE) !== true) {
            throw new AuthorizationException('A signed Discord service callback is required.');
        }

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
        $this->authorizeModerator($request);

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

    private function authorizeModerator(Request $request): User
    {
        $moderator = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        if (! $moderator instanceof User
            || ! $moderator->is_admin
            || ! Gate::forUser($moderator)->allows('manage-war-room')) {
            throw new AuthorizationException('You do not have permission to manage war counters.');
        }

        return $moderator;
    }
}
