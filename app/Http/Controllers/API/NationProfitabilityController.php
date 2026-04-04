<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PWEntityDoesNotExist;
use App\Http\Controllers\Controller;
use App\Services\NationProfitabilityService;
use Illuminate\Http\JsonResponse;
use Throwable;

class NationProfitabilityController extends Controller
{
    public function show(int $nationId, NationProfitabilityService $profitabilityService): JsonResponse
    {
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
