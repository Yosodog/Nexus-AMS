<?php

namespace App\Console\Commands;

use App\Services\Raids\RaidAllianceProfileBuilder;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raids:refresh-alliance-profiles')]
#[Description('Refresh alliance counter rates used by raid valuation')]
final class RefreshRaidAllianceProfiles extends Command
{
    public function handle(RuntimeCapabilities $capabilities, RaidAllianceProfileBuilder $builder): int
    {
        if (! $capabilities->writesPublicWorld()) {
            $this->components->info('Raid alliance profile refresh is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $count = $builder->refreshCounterRates();
        $this->components->info("Refreshed {$count} raid alliance profiles.");

        return self::SUCCESS;
    }
}
