<?php

namespace App\Console\Commands;

use App\Models\Taxes;
use App\Services\SettingService;
use App\Services\TaxService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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