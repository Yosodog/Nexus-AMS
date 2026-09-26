<?php

namespace App\Services\Raids;

use App\Models\RaidTargetProfile;
use App\Services\RuntimeCapabilities;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Flags raid target profiles for rebuild after a world event touches their nation.
 *
 * Marking never interrupts world ingestion; failures are logged and the next event
 * or `raids:rebuild` repairs the profile.
 */
final class RaidProfileDirtyMarker
{
    public function __construct(private RuntimeCapabilities $capabilities) {}

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
     * @param  list<int>  $nationIds
     */
    private function markExisting(array $nationIds): void
    {
        $now = now();

        RaidTargetProfile::query()->whereIn('nation_id', $nationIds)->update(['dirty_at' => $now]);

        $existing = RaidTargetProfile::query()
            ->whereIn('nation_id', $nationIds)
            ->pluck('nation_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $missing = array_diff($nationIds, $existing);

        if ($missing === []) {
            return;
        }

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
        ], array_values($missing)));
    }
}
