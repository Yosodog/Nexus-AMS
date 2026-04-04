<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\RaidFinderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RaidFinderController extends Controller
{
    public function __construct(
        protected RaidFinderService $raidFinderService,
        protected AllianceMembershipService $membershipService
    ) {}

    public function show(?int $nation_id = null)
    {
        $nationId = $nation_id ?? Auth::user()->nation_id;

        $nation = Nation::findOrFail($nationId);

        if (! $this->membershipService->contains($nation->alliance_id)) {
            abort(403, 'You can only run this for your alliance.');
        }

        $cacheKey = "raid-finder:{$nationId}";

        $targets = Cache::remember(
            $cacheKey,
            now()->addMinutes(30),
            fn () => $this->raidFinderService->findTargets($nationId)
                ->map(fn ($target): array => $this->serializeTarget($target))
                ->values()
                ->all()
        );

        return response()->json($targets);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTarget(mixed $target): array
    {
        $nation = data_get($target, 'nation');

        return [
            'nation' => [
                'id' => (int) data_get($nation, 'id', 0),
                'leader_name' => (string) data_get($nation, 'leader_name', ''),
                'alliance' => data_get($nation, 'alliance')
                    ? [
                        'id' => (int) data_get($nation, 'alliance.id', 0),
                        'name' => (string) data_get($nation, 'alliance.name', ''),
                    ]
                    : null,
                'num_cities' => (int) data_get($nation, 'num_cities', 0),
                'last_active' => data_get($nation, 'last_active'),
                'score' => (float) data_get($nation, 'score', 0),
            ],
            'value' => (int) data_get($target, 'value', 0),
            'defensive_wars' => (int) data_get($target, 'defensive_wars', 0),
            'last_beige' => ($lastBeige = data_get($target, 'last_beige')) !== null ? (int) $lastBeige : null,
        ];
    }
}
