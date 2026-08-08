<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Internal;

use App\Http\Controllers\Controller;
use App\Services\RuntimeBuildMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BuildMetadataController extends Controller
{
    public function __invoke(Request $request, RuntimeBuildMetadata $build): JsonResponse
    {
        $requestId = $request->attributes->get('request_id');
        $response = response()->json([
            'contract_version' => RuntimeBuildMetadata::ENDPOINT_CONTRACT,
            'request_id' => is_string($requestId) ? $requestId : null,
            ...$build->metadata(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
