<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\RaidFinderCache;
use App\Services\RaidFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RaidFinderController extends Controller
{
    public function __construct(
        protected RaidFinderService $raidFinderService,
        protected AllianceMembershipService $membershipService,
        protected RaidFinderCache $raidFinderCache,
    ) {}

    public function show(?int $nation_id = null): JsonResponse
    {
        $nationId = $nation_id ?? Auth::user()->nation_id;

        $nation = Nation::findOrFail($nationId);

        if (! $this->membershipService->contains($nation->alliance_id)) {
            abort(403, 'You can only run this for your alliance.');
        }

        $snapshot = $this->raidFinderCache->snapshot($nationId);

        if ($snapshot !== null && $this->raidFinderCache->isFresh($snapshot)) {
            return $this->snapshotResponse($snapshot);
        }

        $lock = Cache::lock($this->raidFinderCache->lockKey($nationId), 45);

        if (! $lock->get()) {
            if ($snapshot !== null) {
                return $this->snapshotResponse($snapshot, stale: true, refreshState: 'refreshing', retryAfter: 2);
            }

            return $this->errorResponse(
                'Raid targets are already being refreshed. Try again shortly.',
                503,
                'temporary_failure',
                2,
            );
        }

        try {
            $snapshot = $this->raidFinderCache->snapshot($nationId);

            if ($snapshot !== null && $this->raidFinderCache->isFresh($snapshot)) {
                return $this->snapshotResponse($snapshot);
            }

            $targets = $this->raidFinderService->findTargets($nationId)
                ->map(fn ($target): array => $this->serializeTarget($target))
                ->values()
                ->all();

            return $this->snapshotResponse($this->raidFinderCache->store($nationId, $targets));
        } catch (Throwable $exception) {
            return $this->recoverOrFail($snapshot, $exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{targets: list<array<string, mixed>>, updated_at: string}|null  $snapshot
     */
    private function recoverOrFail(?array $snapshot, Throwable $exception): JsonResponse
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
        $state = $status === 429 ? 'rate_limited' : 'temporary_failure';

        if ($snapshot !== null) {
            return $this->snapshotResponse($snapshot, stale: true, refreshState: $state, retryAfter: $retryAfter);
        }

        return $this->errorResponse(
            $status === 429
                ? 'Politics & War is rate limiting raid data requests.'
                : 'Raid targets are temporarily unavailable.',
            $status === 429 ? 429 : 503,
            $state,
            $retryAfter,
            $exception,
        );
    }

    /**
     * @param  array{targets: list<array<string, mixed>>, updated_at: string}  $snapshot
     */
    private function snapshotResponse(
        array $snapshot,
        bool $stale = false,
        string $refreshState = 'success',
        ?int $retryAfter = null,
    ): JsonResponse {
        $headers = [
            'X-Nexus-Data-Updated-At' => $snapshot['updated_at'],
            'X-Nexus-Data-Stale' => $stale ? 'true' : 'false',
            'X-Nexus-Async-State' => $refreshState,
        ];

        if ($stale) {
            $appName = trim((string) config('app.name', 'Laravel')) ?: 'Laravel';
            $headers['Warning'] = "110 {$appName} \"Response is stale\"";
        }

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json($snapshot['targets'], 200, $headers);
    }

    private function errorResponse(
        string $message,
        int $status,
        string $state,
        ?int $retryAfter = null,
        ?Throwable $exception = null,
    ): JsonResponse {
        $supportId = (string) Str::uuid();
        $headers = ['X-Nexus-Async-State' => $state];

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

    /**
     * @return array<string, mixed>
     */
    private function serializeTarget(mixed $target): array
    {
        $nation = data_get($target, 'nation');

        return [
            'nation' => [
                'id' => (int) data_get($nation, 'id', 0),
                'leader_name' => (string) data_get($nation, 'leader_name', ''),
                'alliance' => data_get($nation, 'alliance')
                    ? [
                        'id' => (int) data_get($nation, 'alliance.id', 0),
                        'name' => (string) data_get($nation, 'alliance.name', ''),
                    ]
                    : null,
                'num_cities' => (int) data_get($nation, 'num_cities', 0),
                'last_active' => data_get($nation, 'last_active'),
                'score' => (float) data_get($nation, 'score', 0),
            ],
            'value' => (int) data_get($target, 'value', 0),
            'defensive_wars' => (int) data_get($target, 'defensive_wars', 0),
            'last_beige' => ($lastBeige = data_get($target, 'last_beige')) !== null ? (int) $lastBeige : null,
        ];
    }
}
