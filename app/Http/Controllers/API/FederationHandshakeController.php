<?php

namespace App\Http\Controllers\API;

use App\Domain\Federation\Services\FederationAdmissionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FederationHandshakeController extends Controller
{
    public function __construct(private readonly FederationAdmissionService $admission) {}

    public function __invoke(Request $request): JsonResponse
    {
        $message = $this->admission->accept($request->getContent(), true);

        return response()->json([
            'status' => 'accepted',
            'message_id' => $message->message_id,
            'correlation_id' => $message->correlation_id,
        ], 202);
    }
}
