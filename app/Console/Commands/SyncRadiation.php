<?php

namespace App\Console\Commands;

use App\Services\RadiationService;
use Illuminate\Console\Command;

class SyncRadiation extends Command
{
    protected $signature = 'pw:sync-radiation';

    protected $description = 'Fetch and store the current Politics & War radiation snapshot';

    public function __construct(private readonly RadiationService $radiationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshot = $this->radiationService->refresh();

        if (! $snapshot) {
            $this->error('Failed to refresh radiation snapshot.');

            return self::FAILURE;
        }

        $this->info('Radiation snapshot saved for '.$snapshot->snapshot_at?->toDateTimeString().'.');

        return self::SUCCESS;
    }
}
