<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateApplication extends Command
{
    protected $signature = 'app:update';

    protected $description = 'Retired unsafe in-place update command; use the Nexus Setup updater instead';

    public function handle(): int
    {
        $this->error('app:update has been retired because in-place source updates are unsafe.');
        $this->line('Use `nexus update` or the Software updates page in the Nexus admin area.');

        return self::FAILURE;
    }
}
