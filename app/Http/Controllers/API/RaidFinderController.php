<?php

namespace App\Http\Controllers\API;

use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Exceptions\PWQueryFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RaidAvailabilityRequest;
use App\Http\Requests\RaidFinderRequest;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\RaidFinderService;
use App\Services\Raids\RaidAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RaidFinderController extends Controller
{
    public function __construct(
        private readonly RaidFinderService $finder,
        private readonly AllianceMembershipService $membership,
    ) {}

    public function show(RaidFinderRequest $request, ?int $nation_id = null): JsonResponse
    {
        $ownNationId = (int) $request->user()->nation_id;
        $nationId = $nation_id ?? $ownNationId;

        abort_if($nationId !== $ownNationId && ! Gate::allows('view-raids'), 403, 'You can only find raid targets for your own nation.');

        try {
            $result = $this->finder->find($nationId, $request->filters());
        } catch (ProfitabilityPricingUnavailable $exception) {
            return $this->errorResponse('Raid targets are temporarily unavailable.', 503, 'temporary_failure', exception: $exception);
        }

        return response()->json(
            ['data' => $result->rows, 'meta' => $result->meta],
            200,
            ['X-Nexus-Data-Updated-At' => (string) $result->meta['generated_at']],
        );
    }

    public function availability(RaidAvailabilityRequest $request, RaidAvailabilityService $availability): JsonResponse
    {
        $nationId = (int) $request->validated('nation_id');
        $targetId = (int) $request->validated('target_id');
        $nation = Nation::query()->findOrFail($nationId);
        abort_unless($this->membership->contains($nation->alliance_id), 403);

        try {
            return response()->json($availability->check($nationId, $targetId));
        } catch (Throwable $exception) {
            return $this->availabilityErrorResponse($exception);
        }
    }

    private function availabilityErrorResponse(Throwable $exception): JsonResponse
    {
        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 503;
        $headers = $exception instanceof HttpExceptionInterface
            ? $exception->getHeaders()
            : [];
        $retryAfter = isset($headers['Retry-After']) && is_numeric($headers['Retry-After'])
            ? max(1, (int) $headers['Retry-After'])
            : null;

        if ($exception instanceof PWQueryFailedException && $exception->retryAfterSeconds !== null) {
            $status = 429;
            $retryAfter ??= max(1, $exception->retryAfterSeconds);
        }

        $rateLimited = $status === 429;

        return $this->errorResponse(
            $rateLimited
                ? 'Politics & War is rate limiting availability checks.'
                : 'Target availability is temporarily unavailable.',
            $rateLimited ? 429 : 503,
            $rateLimited ? 'rate_limited' : 'temporary_failure',
            $retryAfter,
            $exception,
        );
    }

    private function errorResponse(
        string $message,
        int $status,
        string $state,
        ?int $retryAfter = null,
        ?Throwable $exception = null,
    ): JsonResponse {
        $supportId = (string) Str::uuid();
        $headers = [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        Log::warning('Raid Finder request failed.', [
            'support_id' => $supportId,
            'state' => $state,
            'status' => $status,
            'exception' => $exception ? $exception::class : null,
        ]);

        return response()->json([
            'message' => $message,
            'state' => $state,
            'support_id' => $supportId,
        ], $status, $headers);
    }
}
