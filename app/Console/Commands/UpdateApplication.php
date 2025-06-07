<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class UpdateApplication extends Command
{
    protected $signature = 'app:update 
        {--no-composer : Skip installing composer dependencies} 
        {--no-node : Skip installing Node.js dependencies}';

    protected $description = 'Updates the application by pulling changes, updating dependencies, running migrations, clearing cache, and restarting services';

    /**
     * @return void
     */
    public function handle(): void
    {
        $this->info('Starting application update...');
        Log::info('Application update started.');

        Artisan::call('down');
        $this->info('Application is now in maintenance mode.');

        $this->runShellCommand('git pull origin main', 'Pulling latest code from Git');

        if (!$this->option('no-composer')) {
            $this->runShellCommand(
                'composer install --no-interaction --prefer-dist --optimize-autoloader',
                'Installing Composer dependencies'
            );
        } else {
            $this->info('Skipping Composer dependency installation.');
            Log::info('Skipped Composer install.');
        }

        if (!$this->option('no-node')) {
            $this->runShellCommand(
                'npm install && npm run build',
                'Installing Node.js dependencies and building frontend'
            );
        } else {
            $this->info('Skipping Node.js build.');
            Log::info('Skipped Node.js build.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->info('Migrations applied.');
        Log::info('Migrations applied.');

        Artisan::call('db:seed', ['--force' => true]);
        $this->info('Role seeder completed.');

        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        $this->info('Cleared application caches.');

        //Artisan::call('config:cache'); This causes massive issues for some reason...
        Artisan::call('route:cache');
        Artisan::call('view:cache');
        $this->info('Rebuilt application caches.');

        $this->fixPermissions();

        Artisan::call('queue:restart');
        $this->info('Queue workers restarted.');

        Artisan::call('up');
        $this->info('Application is now live.');
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
            $this->info($description . ' completed.');
            Log::info($description . ' completed.', ['output' => implode("\n", $output)]);
        } else {
            $this->error($description . ' failed.');
            Log::error($description . ' failed.', ['output' => implode("\n", $output)]);
        }
    }

    /**
     * This is assuming the app is running as www-user... we'll deal with this issue later if it becomes a problem
     */
    private function fixPermissions(): void
    {
        $user = 'www-data';
        $paths = ['storage', 'bootstrap/cache'];

        foreach ($paths as $path) {
            $fullPath = base_path($path);
            $this->runShellCommand("chown -R {$user}:{$user} {$fullPath}", "Setting ownership for {$path}");
            $this->runShellCommand(
                "find {$fullPath} -type d -exec chmod 775 {} \\;",
                "Setting directory permissions for {$path}"
            );
            $this->runShellCommand(
                "find {$fullPath} -type f -exec chmod 664 {} \\;",
                "Setting file permissions for {$path}"
            );
        }
    }
}