<?php

namespace App\Console\Commands;

use App\Services\RadiationService;
use Illuminate\Console\Command;

class SyncRadiation extends Command
{
    protected $signature = 'pw:sync-radiation';

    protected $description = 'Fetch and store the current Politics & War world snapshot';

    public function __construct(private readonly RadiationService $radiationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshot = $this->radiationService->refresh();

        if (! $snapshot) {
            $this->error('Failed to refresh the world snapshot.');

            return self::FAILURE;
        }

        $this->info(
            'World snapshot saved for '.$snapshot->snapshot_at?->toDateTimeString()
            .' using game date '.$snapshot->game_date?->toDateString().'.'
        );

        return self::SUCCESS;
    }
}
