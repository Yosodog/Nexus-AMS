<?php

namespace App\Services;

use App\Models\RadiationSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class RadiationService
{
    public function __construct(private readonly GameInfoQueryService $gameInfoQueryService) {}

    public function latest(): ?RadiationSnapshot
    {
        return RadiationSnapshot::query()
            ->latest('snapshot_at')
            ->latest('id')
            ->first();
    }

    public function latestOrRefresh(bool $refreshIfStale = true): ?RadiationSnapshot
    {
        $latest = $this->latest();

        if (! $refreshIfStale) {
            return $latest;
        }

        if ($latest === null || $latest->snapshot_at?->lt(now()->subHours(3))) {
            return $this->refresh() ?? $latest;
        }

        return $latest;
    }

    public function refresh(?Carbon $snapshotAt = null): ?RadiationSnapshot
    {
        try {
            $payload = $this->gameInfoQueryService->getRadiation();

            return RadiationSnapshot::query()->create([
                ...$payload,
                'snapshot_at' => ($snapshotAt ?? now())->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to refresh radiation snapshot: '.$e->getMessage());

            return null;
        }
    }
}
