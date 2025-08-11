<?php

namespace App\Console\Commands;

use App\Services\PWHealthService;
use Illuminate\Console\Command;

class PWHealthCheckCommand extends Command
{
    protected $signature = 'pw:health-check {--ttl=600 : TTL for cache in seconds}';
    protected $description = 'Checks the Politics & War API health and caches the result.';

    /**
     * @param PWHealthService $service
     * @return int
     */
    public function handle(PWHealthService $service): int
    {
        $ok = $service->checkAndCache((int)$this->option('ttl'));
        $this->info($ok ? 'PW API is UP' : 'PW API appears DOWN');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}