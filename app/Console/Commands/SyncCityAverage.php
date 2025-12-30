<?php

namespace App\Console\Commands;

use App\Services\CityCostService;
use Illuminate\Console\Command;

class SyncCityAverage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pw:sync-city-average';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and store the top-20 city average from the Politics & War API';

    public function __construct(private readonly CityCostService $cityCostService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $average = $this->cityCostService->refreshTop20Average();

        if ($average === null) {
            $this->error('Unable to refresh the top-20 city average.');

            return self::FAILURE;
        }

        $this->info('Stored top-20 city average: '.number_format($average, 2));

        return self::SUCCESS;
    }
}
