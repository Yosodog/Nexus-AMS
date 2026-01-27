<?php

namespace App\Http\Controllers\API;

use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\RunWarSimulationRequest;
use App\Models\Nation;
use App\Models\War;
use App\Services\WarSimulator\WarSimulationService;
use App\Services\WarSimulator\WarSimulatorDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarSimulatorController extends Controller
{
    public function defaults(Request $request, WarSimulatorDataService $dataService): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        return response()->json($dataService->buildDefaults($user));
    }

    public function nation(int $nationId, WarSimulatorDataService $dataService): JsonResponse
    {
        $nation = Nation::query()->with(['military', 'cities'])->findOrFail($nationId);

        return response()->json([
            'nation' => $dataService->buildNationSnapshot($nation),
        ]);
    }

    public function war(int $warId, Request $request, WarSimulatorDataService $dataService): JsonResponse
    {
        $war = War::query()->with(['attacker', 'defender'])->findOrFail($warId);

        $this->authorizeWarAccess($request, $war);

        return response()->json($dataService->buildWarPayload($war));
    }

    public function run(RunWarSimulationRequest $request, WarSimulationService $simulationService): JsonResponse
    {
        $dto = WarSimRequestData::fromArray($request->validated());

        return response()->json($simulationService->run($dto));
    }

    private function authorizeWarAccess(Request $request, War $war): void
    {
        $user = $request->user();
        $nationId = $user?->nation_id;

        if ($user?->is_admin) {
            return;
        }

        if ($nationId && ((int) $war->att_id === (int) $nationId || (int) $war->def_id === (int) $nationId)) {
            return;
        }

        abort(403, 'You are not authorized to view this war.');
    }
}
