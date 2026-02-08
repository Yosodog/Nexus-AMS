<?php

namespace App\Console\Commands;

use App\Services\InactivityModeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckInactivity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inactivity:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate member inactivity and execute configured actions.';

    /**
     * Execute the console command.
     */
    public function handle(InactivityModeService $service): int
    {
        $lock = Cache::lock('inactivity:check', 3500);

        if (! $lock->get()) {
            $this->info('Inactivity check already running.');

            return self::SUCCESS;
        }

        try {
            $result = $service->evaluate();
            $processed = $result['processed'] ?? 0;
            $this->info("Inactivity check processed {$processed} nations.");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
