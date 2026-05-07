<?php

namespace App\Console\Commands;

use App\Support\BrowserTestBootstrap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prepare-browser-tests')]
#[Description('Prepare the lightweight browser-test database fixtures')]
class PrepareBrowserTests extends Command
{
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
