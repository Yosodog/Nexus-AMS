<?php

namespace App\Services\Raids;

use App\Models\RaidTargetProfile;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Flags raid target profiles for rebuild after a world event touches their nation.
 *
 * A profile that was marked less than SETTLE_SECONDS ago is left alone: its pending
 * rebuild has not started yet, because rebuilds only pick up and clear marks that are
 * at least that old (see settledCutoff()). An older mark may belong to a rebuild that
 * is already reading nation data, so it is moved forward to now; the rebuild's
 * compare-and-clear then sees a different value and leaves the profile dirty.
 *
 * Marking never interrupts world ingestion; failures are logged and the next event
 * or `raids:rebuild` repairs the profile.
 */
final class RaidProfileDirtyMarker
{
    /**
     * Minimum age of a dirty mark before a rebuild may clear it. Marks inside this window
     * coalesce into one rebuild. Must be at least one second, because dirty_at is stored
     * with second precision.
     */
    public const SETTLE_SECONDS = 60;

    public function __construct(private RuntimeCapabilities $capabilities) {}

    /**
     * Latest dirty_at a rebuild started at $at may clear, truncated to whole seconds.
     */
    public static function settledCutoff(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($at->getTimestamp(), $at->getTimezone())
            ->subSeconds(self::SETTLE_SECONDS);
    }

    /**
     * @param  list<int>  $nationIds
     */
    public function mark(array $nationIds): void
    {
        if (! $this->capabilities->writesPublicWorld()) {
            return;
        }

        $nationIds = array_values(array_unique(array_filter(
            array_map('intval', $nationIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($nationIds === []) {
            return;
        }

        try {
            $this->markExisting($nationIds);
        } catch (Throwable $exception) {
            Log::warning('Raid target profiles could not be marked for rebuild.', [
                'nation_ids' => array_slice($nationIds, 0, 10),
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * Placeholders for nations without a profile are inserted before the conditional
     * update, so a profile a concurrent rebuild inserts in between is still marked.
     *
     * @param  list<int>  $nationIds
     */
    private function markExisting(array $nationIds): void
    {
        $now = CarbonImmutable::now();
        $existing = RaidTargetProfile::query()
            ->whereIn('nation_id', $nationIds)
            ->pluck('nation_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $missing = array_values(array_diff($nationIds, $existing));

        if ($missing !== []) {
            RaidTargetProfile::query()->insertOrIgnore(array_map(fn (int $nationId): array => [
                'nation_id' => $nationId,
                'nation_name' => '',
                'leader_name' => '',
                'score' => 0,
                'num_cities' => 0,
                'color' => '',
                'beige_turns' => 0,
                'vacation_mode_turns' => 0,
                'baseline_kind' => RaidTargetProfile::BASELINE_PRODUCTION_ONLY,
                'baseline_at' => $now,
                'dirty_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing));
        }

        RaidTargetProfile::query()
            ->whereIn('nation_id', $nationIds)
            ->where(fn ($query) => $query
                ->whereNull('dirty_at')
                ->orWhere('dirty_at', '<=', self::settledCutoff($now)))
            ->update(['dirty_at' => $now]);
    }
}
