<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateNationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NationUpdateController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateNation(Request $request): JsonResponse
    {
        // Validate Nexus API Token
        $nexusApiToken = config('services.nexus_api_token');
        $providedToken = $request->header('Authorization');
        Log::debug("Nexus: $nexusApiToken Provided: $providedToken");

        if ($providedToken != "Bearer $nexusApiToken") {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Decode JSON payload
        $nationUpdates = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($nationUpdates)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($nationUpdates['id'])) {
            $nationUpdates = [$nationUpdates];
        }

        // Dispatch the job with an array of nations (single or bulk)
        UpdateNationJob::dispatch($nationUpdates);

        return response()->json(['message' => 'Nation update(s) queued for processing']);
    }
}