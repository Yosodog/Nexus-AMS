<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PWHelperService;
use App\Services\TradePriceService;
use Illuminate\Http\JsonResponse;

class TradePriceController extends Controller
{
    public function average24h(TradePriceService $tradePriceService): JsonResponse
    {
        $average = $tradePriceService->get24hAverage();
        $prices = [];

        foreach (PWHelperService::resources(includeMoney: false, includeCredits: true) as $resource) {
            $prices[$resource] = (int) ($average->{$resource} ?? 0);
        }

        return response()->json([
            'window_hours' => 24,
            'prices' => $prices,
        ]);
    }
}
