<?php

namespace App\Console\Commands;

use App\Support\BrowserTestBootstrap;
use Illuminate\Console\Command;

class PrepareBrowserTests extends Command
{
    protected $signature = 'app:prepare-browser-tests';

    protected $description = 'Prepare the lightweight browser-test database fixtures';

    public function __construct(private readonly BrowserTestBootstrap $browserTestBootstrap)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->browserTestBootstrap->resetAndSeed();

        $this->components->info('Browser test fixtures prepared.');

        return self::SUCCESS;
    }
}
