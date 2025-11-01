<?php

namespace App\Console\Commands;

use App\Services\AllianceMembershipService;
use App\Services\DepositService;
use Illuminate\Console\Command;

class ProcessDeposits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:process-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processes deposits from in-game to Nexus accounts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $primaryAllianceId = app(AllianceMembershipService::class)->getPrimaryAllianceId();

        if ($primaryAllianceId === 0) {
            $this->error('Primary alliance ID is not configured; skipping deposit processing.');

            return;
        }

        DepositService::processDeposits($primaryAllianceId);
    }
}
