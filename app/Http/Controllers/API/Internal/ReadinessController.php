<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Internal;

use App\Http\Controllers\Controller;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadinessController extends Controller
{
    public function __invoke(
        Request $request,
        RuntimeBuildMetadata $build,
        RuntimeReadinessService $readiness,
    ): JsonResponse {
        $snapshot = $readiness->readiness();
        $requestId = $request->attributes->get('request_id');
        $response = response()->json([
            'contract_version' => RuntimeBuildMetadata::ENDPOINT_CONTRACT,
            'status' => $snapshot['status'],
            'checked_at' => $snapshot['checked_at'],
            'request_id' => is_string($requestId) ? $requestId : null,
            ...$build->compatibilityHandshake(),
            'checks' => $snapshot['checks'],
        ], $snapshot['ready'] ? 200 : 503);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
