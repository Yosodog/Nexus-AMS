<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DispatchTestWarAlert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'war-alert:test {--channel=} {--warId=777}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retired legacy war-alert test command.';

    public function handle(): int
    {
        $this->error('The legacy war-alert test command is retired. Use the Milcom v2 incident workflow.');

        return self::FAILURE;
    }
}
