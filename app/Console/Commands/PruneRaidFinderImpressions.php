<?php

namespace App\Console\Commands;

use App\Models\RaidFinderImpression;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raids:prune-finder-impressions')]
#[Description('Delete raid finder impressions older than the retention window')]
final class PruneRaidFinderImpressions extends Command
{
    private const BATCH = 1000;

    public function handle(RuntimeCapabilities $capabilities): int
    {
        if (! $capabilities->writesTenantPrivate()) {
            $this->components->info('Raid finder impressions are unavailable in this runtime.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) config('raids.finder.impression_retention_days'));
        $deleted = 0;

        do {
            $ids = RaidFinderImpression::query()->where('shown_at', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');
            $batch = $ids->isEmpty() ? 0 : RaidFinderImpression::query()->whereIn('id', $ids->all())->delete();
            $deleted += $batch;
        } while ($batch === self::BATCH);

        $this->components->info("Pruned {$deleted} raid finder impressions.");

        return self::SUCCESS;
    }
}
