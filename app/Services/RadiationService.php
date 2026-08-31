<?php

namespace App\Services;

use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyRules;
use App\Services\World\WorldWriteGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RadiationService
{
    public function __construct(
        private readonly GameInfoQueryService $gameInfoQueryService,
        private readonly WorldWriteGuard $worldWriteGuard,
        private readonly RuntimeCapabilities $runtimeCapabilities,
    ) {}

    public function latest(): ?RadiationSnapshot
    {
        return RadiationSnapshot::query()
            ->whereNotNull('game_date')
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

        if (! $this->runtimeCapabilities->writesPublicWorld()) {
            return $latest;
        }

        if (
            $latest === null
            || $latest->snapshot_at === null
            || $latest->snapshot_at->lt(now()->subHours(EconomyRules::WORLD_SNAPSHOT_MAX_AGE_HOURS))
        ) {
            return $this->refresh() ?? $latest;
        }

        return $latest;
    }

    public function refresh(?Carbon $snapshotAt = null): ?RadiationSnapshot
    {
        $this->worldWriteGuard->assertCanWrite(RadiationSnapshot::class);

        try {
            $payload = $this->gameInfoQueryService->getEconomySnapshot();
            $latest = $this->latest();

            if (
                $latest?->game_date !== null
                && $latest->game_date->isAfter(CarbonImmutable::parse($payload['game_date'], 'UTC'))
            ) {
                throw new RuntimeException('The game date regressed behind the latest published snapshot.');
            }

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
