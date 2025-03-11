<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\CreateNationJob;
use App\Jobs\UpdateNationJob;
use App\Models\Nations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubUpdateController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateNation(Request $request): JsonResponse
    {
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

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function createNation(Request $request)
    {
        // Decode JSON payload
        $nationCreate = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($nationCreate)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($nationUpdates['id'])) {
            $nationCreate = [$nationCreate];
        }

        // Dispatch the job with an array of nations (single or bulk)
        CreateNationJob::dispatch($nationCreate);

        return response()->json(['message' => 'Nation creation(s) queued for processing']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteNation(Request $request)
    {
        // Decode JSON payload
        $nationDelete = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($nationDelete)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($nationDelete['id'])) {
            $nationCreate = [$nationDelete];
        }

        foreach ($nationDelete as $del) {
            $nation = Nations::getNationById($del['id']);
            $nation->delete();
        }

        return response()->json(['message' => 'Nation deleted successfully']);
    }
}