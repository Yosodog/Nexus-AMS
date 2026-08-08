<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Internal;

use App\Http\Controllers\Controller;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RuntimeHealthController extends Controller
{
    public function __invoke(
        Request $request,
        RuntimeBuildMetadata $build,
        RuntimeReadinessService $health,
    ): JsonResponse {
        $snapshot = $health->deepHealth();
        $requestId = $request->attributes->get('request_id');
        $response = response()->json([
            'contract_version' => RuntimeBuildMetadata::ENDPOINT_CONTRACT,
            'status' => $snapshot['status'],
            'checked_at' => $snapshot['checked_at'],
            'request_id' => is_string($requestId) ? $requestId : null,
            ...$build->compatibilityHandshake(),
            'checks' => $snapshot['checks'],
        ], $snapshot['healthy'] ? 200 : 503);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
