<?php

namespace App\Http\Controllers\API\Milcom;

use App\Http\Controllers\Controller;
use App\Models\MilcomEvent;
use App\Models\User;
use App\Services\Milcom\MilcomAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private readonly MilcomAlertService $alerts) {}

    public function dismiss(Request $request, MilcomEvent $event): JsonResponse
    {
        $this->authorize('manage-war-room');

        /** @var User $actor */
        $actor = $request->user();
        $dismissedEvent = $this->alerts->dismiss($actor, $event);

        return response()->json([
            'data' => [
                'event_id' => $dismissedEvent->id,
                'dismissed_at' => $dismissedEvent->dismissed_at?->toIso8601String(),
                'dismissed_by_user_id' => $dismissedEvent->dismissed_by_user_id,
            ],
            'meta' => ['updated_at' => now()->toIso8601String()],
            'links' => [],
            'message' => 'Raid-policy alert dismissed.',
        ]);
    }
}
