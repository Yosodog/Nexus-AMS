<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Discord\DiscordMilcomProjectionRequest;
use App\Http\Requests\Discord\DiscordMilcomReadinessRequest;
use App\Http\Resources\Discord\MilcomAssignmentResource;
use App\Http\Resources\Discord\MilcomReadinessResource;
use App\Http\Resources\Discord\MilcomWarRoomResource;
use App\Services\Discord\DiscordMilcomReadProvider;
use Illuminate\Http\JsonResponse;

final class MilcomProjectionController extends Controller
{
    use DiscordApiResponses;

    public function __construct(private readonly DiscordMilcomReadProvider $provider) {}

    public function assignments(DiscordMilcomProjectionRequest $request): JsonResponse
    {
        $assignments = $this->provider->currentAssignments($request->actor());

        return $this->discordData(
            MilcomAssignmentResource::collection($assignments)->resolve($request),
            meta: $this->meta('actor_current_assignments'),
        );
    }

    public function readiness(DiscordMilcomReadinessRequest $request): JsonResponse
    {
        $actor = $request->actor();
        $nationId = $request->nationId();
        $readiness = $this->provider->readiness($actor, $nationId);

        if ($readiness === null) {
            return $this->discordError(
                'readiness_not_found',
                'No Milcom-v2 readiness snapshot is available for this nation.',
                404,
            );
        }

        return $this->discordData(
            (new MilcomReadinessResource($readiness))->resolve($request),
            meta: $this->meta($nationId === null || $nationId === (int) $actor->nation_id
                ? 'actor_self'
                : 'authorized_nation'),
        );
    }

    public function warRoom(
        DiscordMilcomProjectionRequest $request,
        int $objective,
    ): JsonResponse {
        $warRoom = $this->provider->warRoom($request->actor(), $objective);

        if ($warRoom === null) {
            return $this->discordError(
                'war_room_not_found',
                'This Milcom-v2 objective does not have an active Discord war room.',
                404,
            );
        }

        return $this->discordData(
            (new MilcomWarRoomResource($warRoom))->resolve($request),
            meta: $this->meta('participant_or_manager'),
        );
    }

    /** @return array{provider: string, projection_schema_version: int, actor_scope: string, generated_at: string} */
    private function meta(string $actorScope): array
    {
        return [
            'provider' => 'nexus_milcom_v2',
            'projection_schema_version' => 1,
            'actor_scope' => $actorScope,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
