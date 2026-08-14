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

            foreach ($this->cacheRefreshSteps() as [$arguments, $description]) {
                $this->runArtisanCommand($arguments, $description);
            }

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

    private function fixPermissions(): void
    {
        $paths = ['storage', 'bootstrap/cache'];

        foreach ($paths as $path) {
            $fullPath = base_path($path);

            foreach ($this->permissionCommands($fullPath, $path) as [$command, $description]) {
                $this->runShellCommand($command, $description);
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    protected function cacheRefreshSteps(): array
    {
        return [
            ['config:clear', 'Clearing config cache'],
            ['cache:clear', 'Clearing application cache'],
            ['route:clear', 'Clearing route cache'],
            ['event:clear', 'Clearing event cache'],
            ['view:clear', 'Clearing compiled views'],
            ['route:cache', 'Rebuilding route cache'],
            ['event:cache', 'Rebuilding event cache'],
            ['view:cache', 'Rebuilding view cache'],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function permissionCommands(string $fullPath, string $pathLabel): array
    {
        $owner = trim((string) config('deployment.owner'));
        $group = trim((string) config('deployment.group'));
        $directoryMode = $this->normalizePermissionMode((string) config('deployment.directory_mode'), 'directory');
        $fileMode = $this->normalizePermissionMode((string) config('deployment.file_mode'), 'file');

        if ($owner === '' || $group === '') {
            throw new RuntimeException('Deployment owner and group must be configured.');
        }

        $ownership = escapeshellarg("{$owner}:{$group}");
        $escapedPath = escapeshellarg($fullPath);

        return [
            ["chown -R -- {$ownership} {$escapedPath}", "Setting ownership for {$pathLabel}"],
            ["find {$escapedPath} -type d -exec chmod {$directoryMode} {} +", "Setting directory permissions for {$pathLabel}"],
            ["find {$escapedPath} -type f -exec chmod {$fileMode} {} +", "Setting file permissions for {$pathLabel}"],
        ];
    }

    private function normalizePermissionMode(string $mode, string $label): string
    {
        $mode = trim($mode);

        if (! preg_match('/^0?[0-7]{3}$/', $mode)) {
            throw new RuntimeException("Invalid {$label} permission mode [{$mode}].");
        }

        return str_pad(ltrim($mode, '0'), 4, '0', STR_PAD_LEFT);
    }
}
