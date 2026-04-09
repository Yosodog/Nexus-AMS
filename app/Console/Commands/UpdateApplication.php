<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpdateApplication extends Command
{
    protected $signature = 'app:update 
        {--no-composer : Skip installing composer dependencies} 
        {--no-node : Skip installing Node.js dependencies}';

    protected $description = 'Updates the application by pulling changes, updating dependencies, running migrations, clearing cache, and restarting services';

    public function handle(): int
    {
        $this->info('Starting application update...');
        Log::info('Application update started.');

        try {
            $this->runArtisanCommand('down', 'Putting application into maintenance mode');
            $this->runShellCommand('git pull origin main', 'Pulling latest code from Git');

            if (! $this->option('no-composer')) {
                $this->runShellCommand(
                    'composer install --no-interaction --prefer-dist --optimize-autoloader',
                    'Installing Composer dependencies'
                );
            } else {
                $this->info('Skipping Composer dependency installation.');
                Log::info('Skipped Composer install.');
            }

            if (! $this->option('no-node')) {
                $this->runShellCommand(
                    'node -e "const major = Number(process.versions.node.split(\'.\')[0]); if (major < 20) { console.error(\'Node.js 20 or newer is required for the frontend build.\'); process.exit(1); }"',
                    'Checking Node.js runtime'
                );
                $this->runShellCommand(
                    'npm ci && npm run build',
                    'Installing Node.js dependencies and building frontend'
                );
            } else {
                $this->info('Skipping Node.js build.');
                Log::info('Skipped Node.js build.');
            }

            $this->runArtisanCommand('migrate --force', 'Applying migrations');
            $this->runArtisanCommand('db:seed --force', 'Running database seeders');
            $this->runArtisanCommand('config:clear', 'Clearing config cache');
            $this->runArtisanCommand('cache:clear', 'Clearing application cache');
            $this->runArtisanCommand('route:clear', 'Clearing route cache');
            $this->runArtisanCommand('view:clear', 'Clearing compiled views');
            $this->runArtisanCommand('route:cache', 'Rebuilding route cache');
            $this->runArtisanCommand('view:cache', 'Rebuilding view cache');

            $this->fixPermissions();

            $this->runArtisanCommand('queue:restart', 'Restarting queue workers');

            Log::info('Application update completed successfully.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            Log::error('Application update failed.', [
                'message' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        } finally {
            try {
                $this->runArtisanCommand('up', 'Bringing application back online');
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
                Log::error('Failed to bring the application back online after update.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ensure there is NEVER any user input for the command.
     */
    private function runShellCommand(string $command, string $description): void
    {
        $this->info($description.'...');
        $output = [];
        exec($command.' 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            $this->info($description.' completed.');
            Log::info($description.' completed.', ['output' => implode("\n", $output)]);
        } else {
            $this->error($description.' failed.');
            Log::error($description.' failed.', ['output' => implode("\n", $output)]);
            throw new RuntimeException($description.' failed: '.implode("\n", $output));
        }
    }

    private function runArtisanCommand(string $arguments, string $description): void
    {
        $phpBinary = escapeshellarg(PHP_BINARY);
        $artisanBinary = escapeshellarg(base_path('artisan'));

        $this->runShellCommand("{$phpBinary} {$artisanBinary} {$arguments}", $description);
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
