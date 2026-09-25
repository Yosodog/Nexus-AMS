<?php

namespace App\Console\Commands;

use App\Models\RaidTargetProfile;
use App\Services\Raids\RaidTargetProfileBuilder;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raids:process-profiles {--limit=1000 : Maximum dirty profiles to rebuild}')]
#[Description('Rebuild raid target profiles flagged by world events')]
final class ProcessRaidProfiles extends Command
{
    private const CHUNK = 200;

    public function handle(RuntimeCapabilities $capabilities, RaidTargetProfileBuilder $builder): int
    {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid profile processing is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->components->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $nationIds = RaidTargetProfile::query()
            ->whereNotNull('dirty_at')
            ->orderBy('dirty_at')
            ->limit($limit)
            ->pluck('nation_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $written = 0;

        foreach (array_chunk($nationIds, self::CHUNK) as $chunk) {
            $written += $builder->build($chunk);
        }

        $this->components->info("Rebuilt {$written} raid target profiles.");

        return self::SUCCESS;
    }
}
