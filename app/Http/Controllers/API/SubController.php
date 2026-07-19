<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionEventProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SubController extends Controller
{
    public function __construct(private readonly SubscriptionEventProcessor $processor) {}

    public function updateNation(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'nation', 'update')) {
            return $error;
        }

        return response()->json(['message' => 'Nation update(s) queued for processing']);
    }

    public function createNation(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'nation', 'create')) {
            return $error;
        }

        return response()->json(['message' => 'Nation creation(s) queued for processing']);
    }

    public function deleteNation(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'nation', 'delete')) {
            return $error;
        }

        return response()->json(['message' => 'Nation deleted successfully']);
    }

    public function createAlliance(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'alliance', 'create')) {
            return $error;
        }

        return response()->json(['message' => 'Alliance created successfully']);
    }

    public function updateAlliance(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'alliance', 'update')) {
            return $error;
        }

        return response()->json(['message' => 'Alliance update(s) queued for processing']);
    }

    public function deleteAlliance(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'alliance', 'delete')) {
            return $error;
        }

        return response()->json(['message' => 'Alliance deleted successfully']);
    }

    public function createCity(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'city', 'create')) {
            return $error;
        }

        return response()->json(['message' => 'City creation(s) queued for processing']);
    }

    public function updateCity(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'city', 'update')) {
            return $error;
        }

        return response()->json(['message' => 'Alliance update(s) queued for processing']);
    }

    public function deleteCity(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'city', 'delete')) {
            return $error;
        }

        return response()->json(['message' => 'Alliance deleted successfully']);
    }

    public function createWar(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'war', 'create')) {
            return $error;
        }

        return response()->json(['message' => 'War created successfully']);
    }

    public function updateWar(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'war', 'update')) {
            return $error;
        }

        return response()->json(['message' => 'War update(s) queued for processing']);
    }

    public function deleteWar(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'war', 'delete')) {
            return $error;
        }

        return response()->json(['message' => 'War(s) deleted successfully']);
    }

    public function createWarAttack(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'warattack', 'create')) {
            return $error;
        }

        return response()->json(['message' => 'War attack(s) queued for processing']);
    }

    public function createAccount(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'account', 'create')) {
            return $error;
        }

        return response()->json(['message' => 'Account(s) queued for creation']);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'account', 'update')) {
            return $error;
        }

        return response()->json(['message' => 'Account update(s) queued for processing']);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        if ($error = $this->process($request, 'account', 'delete')) {
            return $error;
        }

        return response()->json(['message' => 'Account deletion(s) queued for processing']);
    }

    private function process(Request $request, string $model, string $event): ?JsonResponse
    {
        try {
            $this->processor->process($model, $event, $request->json()->all());

            return null;
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }
}
