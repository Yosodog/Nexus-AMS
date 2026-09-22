<?php

namespace Tests\Unit;

use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateApplicationPermissionsTest extends TestCase
{
    public function test_update_command_is_permanently_retired(): void
    {
        $this->artisan('app:update')
            ->expectsOutput('app:update has been retired because in-place source updates are unsafe.')
            ->expectsOutput('Use `nexus update` or the Software updates page in the Nexus admin area.')
            ->assertExitCode(Command::FAILURE);
    }
}
