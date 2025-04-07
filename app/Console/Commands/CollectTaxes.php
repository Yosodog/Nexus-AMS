<?php

namespace App\Console\Commands;

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
        $lastScanned = TaxService::updateAllianceTaxes(env("PW_ALLIANCE_ID"));

        $this->info("Updated alliance taxes. Last scanned ID: $lastScanned");
    }
}