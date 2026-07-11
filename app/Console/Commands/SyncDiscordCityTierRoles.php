<?php

namespace App\Console\Commands;

use App\Jobs\SyncDiscordCityTierRolesJob;
use Illuminate\Console\Command;

class SyncDiscordCityTierRoles extends Command
{
    protected $signature = 'discord:sync-city-tiers';

    protected $description = 'Queue reconciliation of managed Discord city-tier roles';

    public function handle(): int
    {
        SyncDiscordCityTierRolesJob::dispatch();

        $this->info('Queued Discord city-tier role synchronization.');

        return self::SUCCESS;
    }
}
