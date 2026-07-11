<?php

namespace App\Jobs;

use App\Services\Discord\DiscordCityTierSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncDiscordCityTierRolesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(DiscordCityTierSyncService $cityTierSync): void
    {
        $cityTierSync->queueSnapshot();
    }

    public function uniqueId(): string
    {
        return 'discord-city-tier-sync';
    }
}
