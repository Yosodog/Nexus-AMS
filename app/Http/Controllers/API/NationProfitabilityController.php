<?php

namespace App\Http\Controllers\API;

use App\Exceptions\ProfitabilityContextUnavailable;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Exceptions\PWEntityDoesNotExist;
use App\Http\Controllers\Controller;
use App\Services\AllianceMemberEligibilityService;
use App\Services\NationProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            $result = $profitabilityService->calculateLiveNationProfitabilityById($nationId);
            $result['calculation_context'] = Arr::except(
                (array) ($result['calculation_context'] ?? []),
                [
                    'military_research',
                    'treasure_income_modifier',
                    'color_turn_bonus',
                ]
            );

            return response()->json($result);
        } catch (PWEntityDoesNotExist) {
            return response()->json([
                'message' => 'Nation not found.',
            ], 404);
        } catch (ProfitabilityPricingUnavailable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'PROFITABILITY_PRICING_UNAVAILABLE',
            ], 503);
        } catch (ProfitabilityContextUnavailable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'PROFITABILITY_CONTEXT_UNAVAILABLE',
            ], 503);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Failed to calculate nation profitability.',
            ], 502);
        }
    }
}
