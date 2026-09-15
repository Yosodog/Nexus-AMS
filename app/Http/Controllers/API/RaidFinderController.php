<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PWQueryFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RaidAvailabilityRequest;
use App\Jobs\RefreshRaidFinder;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\RaidFinderCache;
use App\Services\RaidFinderService;
use App\Services\RaidIntelligenceRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RaidFinderController extends Controller
{
    private ?int $requestedNationId = null;

    public function __construct(
        protected RaidFinderService $raidFinderService,
        protected AllianceMembershipService $membershipService,
        protected RaidFinderCache $raidFinderCache,
    ) {}

    public function show(?int $nation_id = null): JsonResponse
    {
        $nationId = $nation_id ?? Auth::user()->nation_id;
        $this->requestedNationId = (int) $nationId;

        $nation = Nation::findOrFail($nationId);

        if (! $this->membershipService->contains($nation->alliance_id)) {
            abort(403, 'You can only run this for your alliance.');
        }

        $snapshot = $this->raidFinderCache->snapshot($nationId);

        if ($snapshot !== null && $this->raidFinderCache->isFresh($snapshot)) {
            return $this->snapshotResponse($snapshot);
        }

        if (config('queue.default') !== 'sync') {
            if (request()->boolean('poll')) {
                $completed = $snapshot !== null && ($snapshot['complete'] ?? true)
                    && $snapshot['updated_at'] !== request()->query('after');
                if ($completed) {
                    return $this->snapshotResponse($snapshot, stale: true);
                }
                if (Cache::get($this->raidFinderCache->failureKey($nationId))) {
                    return $this->errorResponse('Raid predictions could not be completed. Please retry shortly.', 503, 'temporary_failure', 30);
                }

                return $snapshot !== null
                    ? $this->snapshotResponse($snapshot, stale: true, refreshState: 'refreshing', retryAfter: 2)
                    : response()->json([], 202, ['X-Nexus-Async-State' => 'refreshing', 'Retry-After' => '2']);
            }
            if ($snapshot !== null && ($snapshot['complete'] ?? true) && Cache::get($this->raidFinderCache->completedKey($nationId))) {
                return $this->snapshotResponse($snapshot, stale: true);
            }
            if (Cache::get($this->raidFinderCache->failureKey($nationId))) {
                return $snapshot !== null
                    ? $this->snapshotResponse($snapshot, stale: true, refreshState: 'temporary_failure', retryAfter: 30)
                    : $this->errorResponse('Raid predictions could not be completed. Please retry shortly.', 503, 'temporary_failure', 30);
            }
            $refreshLock = Cache::lock($this->raidFinderCache->lockKey($nationId), 600);
            if ($refreshLock->get()) {
                try {
                    Cache::forget($this->raidFinderCache->completedKey($nationId));
                    RefreshRaidFinder::dispatch($nationId, $refreshLock->owner());
                } catch (Throwable $exception) {
                    $refreshLock->release();

                    return $this->recoverOrFail($snapshot, $exception);
                }
            }

            return $snapshot !== null
                ? $this->snapshotResponse($snapshot, stale: true, refreshState: 'refreshing', retryAfter: 2)
                : response()->json([], 202, ['X-Nexus-Async-State' => 'refreshing', 'Retry-After' => '2']);
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

            $revision = $this->raidFinderCache->key($nationId);
            $targets = $this->raidFinderService->findTargets($nationId)
                ->map(fn ($target): array => $this->serializeTarget($target))
                ->values()
                ->all();

            return $this->snapshotResponse($this->raidFinderCache->store($nationId, $targets, $revision));
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

        $targets = collect($snapshot['targets']);
        $availability = [];
        if ($this->requestedNationId !== null) {
            $targetIds = $targets
                ->filter(fn (array $target): bool => isset($target['availability'], $target['nation']['id']))
                ->pluck('nation.id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
            if ($targetIds !== []) {
                $availability = $this->raidFinderService->availabilityBatch($this->requestedNationId, $targetIds);
            }
        }
        $targets = $targets->map(function (array $target) use ($availability): array {
            $targetId = (int) data_get($target, 'nation.id', 0);
            if ($targetId > 0 && array_key_exists($targetId, $availability)) {
                $target['availability'] = $availability[$targetId];
                $target['defensive_wars'] = $target['availability']['defensive_wars'];
            }

            return $target;
        })->reject(fn (array $target): bool => isset($target['availability']) && $target['availability']['eligible'] === false && ! ($target['availability']['planning_only'] ?? false))->values()->all();

        return response()->json($targets, 200, $headers);
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

    /** @return array<string, mixed> */
    private function serializeTarget(mixed $target): array
    {
        $nation = data_get($target, 'nation');
        $row = [
            'nation' => [
                'id' => (int) data_get($nation, 'id', 0),
                'leader_name' => (string) data_get($nation, 'leader_name', ''),
                'alliance' => data_get($nation, 'alliance') ? [
                    'id' => (int) data_get($nation, 'alliance.id', 0),
                    'name' => (string) data_get($nation, 'alliance.name', ''),
                ] : null,
                'num_cities' => (int) data_get($nation, 'num_cities', 0),
                'last_active' => data_get($nation, 'last_active'),
                'score' => (float) data_get($nation, 'score', 0),
            ],
            'value' => data_get($target, 'value'),
            'defensive_wars' => (int) data_get($target, 'defensive_wars', 0),
            'last_beige' => data_get($target, 'last_beige'),
        ];
        foreach (['prediction', 'calculation', 'intelligence', 'availability'] as $key) {
            if (data_get($target, $key) !== null) {
                $row[$key] = data_get($target, $key);
            }
        }
        foreach (['soldiers', 'tanks', 'aircraft', 'ships'] as $unit) {
            if (data_get($nation, $unit) !== null) {
                $row['nation'][$unit] = (int) data_get($nation, $unit);
            }
        }

        return $row;
    }

    public function availability(
        RaidAvailabilityRequest $request,
        RaidIntelligenceRefreshService $refresh,
    ): JsonResponse {
        $nationId = (int) $request->validated('nation_id');
        $targetId = (int) $request->validated('target_id');
        $nation = Nation::query()->findOrFail($nationId);
        abort_unless($this->membershipService->contains($nation->alliance_id), 403);

        try {
            $current = $refresh->currentAvailability([$nationId, $targetId]);

            return response()->json($this->raidFinderService->availability($nationId, $targetId, $current));
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
}
