<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Console\Command;

class ReapDiscordQueueLeases extends Command
{
    protected $signature = 'discord-queue:reap-leases';

    protected $description = 'Release expired Discord queue leases for retry or terminal failure';

    public function handle(DiscordQueueLeaseService $leaseService): int
    {
        $count = $leaseService->reapExpiredLeases();

        $this->info("Reaped {$count} expired Discord queue lease(s).");

        return self::SUCCESS;
    }
}
