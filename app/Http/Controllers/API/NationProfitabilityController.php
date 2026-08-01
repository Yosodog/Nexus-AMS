<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PWEntityDoesNotExist;
use App\Http\Controllers\Controller;
use App\Services\AllianceMemberEligibilityService;
use App\Services\NationProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NationProfitabilityController extends Controller
{
    public function show(
        Request $request,
        int $nationId,
        AllianceMemberEligibilityService $memberEligibilityService,
        NationProfitabilityService $profitabilityService
    ): JsonResponse {
        $memberEligibilityService->nationFor($request->user());

        try {
            return response()->json(
                $profitabilityService->calculateLiveNationProfitabilityById($nationId)
            );
        } catch (PWEntityDoesNotExist) {
            return response()->json([
                'message' => 'Nation not found.',
            ], 404);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Failed to calculate nation profitability.',
            ], 502);
        }
    }
}
