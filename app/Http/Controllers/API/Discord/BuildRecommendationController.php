<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Models\User;
use App\Services\Discord\DiscordBuildRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BuildRecommendationController extends Controller
{
    use DiscordApiResponses;

    public function __invoke(Request $request, DiscordBuildRecommendationService $recommendations): JsonResponse
    {
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
        abort_unless($actor instanceof User, 503, 'Discord actor context is unavailable.');

        return $this->discordData(
            $recommendations->forActor($actor),
            meta: [
                'provider' => 'nexus_build_recommendations',
                'projection_schema_version' => 1,
                'actor_scope' => 'self',
                'generated_at' => now()->toIso8601String(),
            ],
        );
    }
}
