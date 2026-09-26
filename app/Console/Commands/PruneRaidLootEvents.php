<?php

namespace App\Console\Commands;

use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raids:prune-loot-events')]
#[Description('Delete expired raid loot events that are not a current profile baseline')]
final class PruneRaidLootEvents extends Command
{
    private const BATCH = 1000;

    public function handle(RuntimeCapabilities $capabilities): int
    {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid loot event pruning is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) config('raids.loot_event_retention_days'));
        $deleted = 0;

        do {
            $ids = RaidLootEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->whereNotIn('id', RaidTargetProfile::query()->select('baseline_attack_id')->whereNotNull('baseline_attack_id'))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');
            $batch = $ids->isEmpty() ? 0 : RaidLootEvent::query()->whereIn('id', $ids->all())->delete();
            $deleted += $batch;
        } while ($batch === self::BATCH);

        $this->components->info("Pruned {$deleted} raid loot events.");

        return self::SUCCESS;
    }
}
