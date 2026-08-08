<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ArchiveStaleWarCounters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'war-counters:archive-stale';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retired legacy war-counter archive command.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->error('The legacy war-counter archive command is retired. Use Milcom v2 history.');

        return self::FAILURE;
    }
}
