<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntelReportRequest;
use App\Services\IntelReportParser;
use App\Services\IntelReportService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class IntelReportController extends Controller
{
    public function store(
        StoreIntelReportRequest $request,
        IntelReportParser $parser,
        IntelReportService $service
    ): JsonResponse {
        try {
            $parsed = $parser->parse($request->input('report'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $report = $service->store($parsed, $request->input('source', 'discord'));

        return response()->json([
            'id' => $report->id,
            'nation_id' => $report->nation_id,
            'nation_name' => $report->nation_name,
            'was_detected' => $report->was_detected,
            'spies_captured' => $report->spies_captured,
        ], 201);
    }
}
