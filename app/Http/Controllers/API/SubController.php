<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\Http\Controllers\Controller;
use App\Jobs\CreateNationJob;
use App\Jobs\UpdateAllianceJob;
use App\Jobs\UpdateCityJob;
use App\Jobs\UpdateNationJob;
use App\Models\Alliances;
use App\Models\Cities;
use App\Models\Nations;
use App\Services\AllianceQueryService;
use App\Services\CityQueryService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubController extends Controller
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
    public function createNation(Request $request): JsonResponse
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
    public function deleteNation(Request $request): JsonResponse
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

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function createAlliance(Request $request): JsonResponse
    {
        // Decode JSON payload
        $allianceCreates = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($allianceCreates)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if (isset($allianceCreates['id'])) {
            $allianceCreates = [$allianceCreates];
        }

        foreach ($allianceCreates as $create) {
            $alliance = AllianceQueryService::getAllianceById($create['id']);

            Alliances::updateFromAPI($alliance);
        }

        return response()->json(['message' => 'Alliance created successfully']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateAlliance(Request $request): JsonResponse
    {
        // Decode JSON payload
        $allianceUpdates = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($allianceUpdates)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($allianceUpdates['id'])) {
            $allianceUpdates = [$allianceUpdates];
        }

        // Dispatch the job with an array of nations (single or bulk)
        UpdateAllianceJob::dispatch($allianceUpdates);

        return response()->json(['message' => 'Alliance update(s) queued for processing']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteAlliance(Request $request): JsonResponse
    {
        // Decode JSON payload
        $allianceDeletes = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($allianceDeletes)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($allianceDeletes['id'])) {
            $allianceDeletes = [$allianceDeletes];
        }

        foreach ($allianceDeletes as $del) {
            $nation = Alliances::getById($del['id']);
            $nation->delete();
        }

        return response()->json(['message' => 'Alliance deleted successfully']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public function createCity(Request $request)
    {
        // Decode JSON payload
        $cityCreates = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($cityCreates)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if (isset($cityCreates['id'])) {
            $cityCreates = [$cityCreates];
        }

        foreach ($cityCreates as $create) {
            $city = CityQueryService::getCityById($create['id']);

            Cities::updateFromAPI($city);
        }

        return response()->json(['message' => 'City created successfully']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateCity(Request $request)
    {
        // Decode JSON payload
        $citiesData = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($citiesData)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($citiesData['id'])) {
            $citiesData = [$citiesData];
        }

        // Dispatch the job with an array of nations (single or bulk)
        UpdateCityJob::dispatch($citiesData);

        return response()->json(['message' => 'Alliance update(s) queued for processing']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteCity(Request $request)
    {
        // Decode JSON payload
        $citiesDelete = $request->json()->all();

        // Ensure it's always an array
        if (!is_array($citiesDelete)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // If it's a single nation update (not an array of nations), wrap it in an array
        if (isset($citiesDelete['id'])) {
            $citiesDelete = [$citiesDelete];
        }

        foreach ($citiesDelete as $del) {
            $nation = Cities::getById($del['id']);
            $nation->delete();
        }

        return response()->json(['message' => 'Alliance deleted successfully']);
    }
}