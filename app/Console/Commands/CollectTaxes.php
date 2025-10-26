<?php

namespace App\Console\Commands;

use App\Services\AllianceMembershipService;
use App\Services\TaxService;
use Illuminate\Console\Command;

class CollectTaxes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'taxes:collect';
    /**
     * @var string
     */
    protected $description = 'Collects taxes for the alliance';

    /**
     * @return void
     */
    public function handle(): void
    {
        $primaryAllianceId = app(AllianceMembershipService::class)->getPrimaryAllianceId();

        if ($primaryAllianceId === 0) {
            $this->error('Primary alliance ID is not configured.');

            return;
        }

        $lastScanned = TaxService::updateAllianceTaxes($primaryAllianceId);

        $this->info("Updated alliance taxes. Last scanned ID: $lastScanned");
    }
}