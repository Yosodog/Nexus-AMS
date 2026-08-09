<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Models\User;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordProviderDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StatusController extends Controller
{
    public function __construct(private readonly DiscordProviderDiagnostics $diagnostics) {}

    public function __invoke(Request $request): JsonResponse
    {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
        abort_unless($actor instanceof User, 503, 'Discord actor context is unavailable.');
        Gate::forUser($actor)->authorize('view-diagnostic-info');

        return response()->json([
            'data' => $this->diagnostics->forConnection($connection),
            'meta' => [
                'contract_version' => 1,
                'relay_protocol' => (int) $request->attributes->get(
                    VerifyDiscordInteraction::PROTOCOL_ATTRIBUTE,
                    1,
                ),
            ],
        ]);
    }
}
