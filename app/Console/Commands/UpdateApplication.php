<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class UpdateApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update {--no-composer : Skip installing composer dependencies} {--no-node : Skip installing Node.js dependencies}';
    /**
     * @var string
     */
    protected $description = 'Updates the application by pulling changes, updating dependencies, running migrations, clearing cache, and restarting services';

    /**
     * @return void
     */
    public function handle(): void
    {
        $this->info('Starting application update...');
        Log::info('Application update started.');

        $this->info("Bringing application offline for update.");
        Artisan::call("down");

        $this->runShellCommand('git pull origin main', 'Pulling latest code from GitHub');

        if (!$this->option('no-composer')) {
            $this->runShellCommand(
                'composer install --no-interaction --prefer-dist --optimize-autoloader',
                'Updating Composer dependencies'
            );
        } else {
            $this->info('Skipping Composer dependencies update.');
            Log::info('Skipping Composer dependencies update.');
        }

        if (!$this->option('no-node')) {
            $this->runShellCommand(
                'npm install && npm run build',
                'Updating Node.js dependencies and building frontend'
            );
        } else {
            $this->info('Skipping Node.js dependencies update.');
            Log::info('Skipping Node.js dependencies update.');
        }

        Artisan::call('migrate --force');
        $this->info('Database migrations applied successfully.');
        Log::info('Database migrations applied successfully.');

        $this->info("Updating permissions");
        Artisan::call('db:seed --class=RoleSeeder --force');
        
        // Clear cache
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        $this->info('Application cache cleared.');
        Log::info('Application cache cleared.');

        // Then re-cache
        Artisan::call('config:cache');
        Artisan::call('route:cache');
        Artisan::call('view:cache');

        $this->info("Rebuilt cache.");
        Log::info('Application cache rebuilt.');

        $this->info('Restarting queue workers');
        Artisan::call('queue:restart');

        $this->info("Bringing application back online.");
        Artisan::call("up");

        $this->info('Application update completed successfully.');
        Log::info('Application update completed successfully.');
    }

    /**
     * Ensure there is NEVER any user input for the command.
     *
     * @param string $command
     * @param string $description
     * @return void
     */
    private function runShellCommand(string $command, string $description): void
    {
        $this->info($description . '...');
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            $this->info($description . ' completed successfully.');
            Log::info($description . ' completed successfully.');
        } else {
            $this->error($description . ' failed. Check logs for details.');
            Log::error($description . ' failed.', ['output' => $output]);
        }
    }
}