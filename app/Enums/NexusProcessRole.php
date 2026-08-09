<?php

declare(strict_types=1);

namespace App\Enums;

enum NexusProcessRole: string
{
    case Web = 'web';
    case Queue = 'queue';
    case Scheduler = 'scheduler';
    case Migrator = 'migrator';
    case Bootstrap = 'bootstrap';
    case TenantEventConsumer = 'event-consumer';

    /**
     * @return non-empty-list<string>
     */
    public function command(string $applicationRoot, string $phpBinary = PHP_BINARY): array
    {
        $artisan = rtrim($applicationRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'artisan';

        return match ($this) {
            self::Web => ['/usr/local/bin/apache2-foreground'],
            self::Queue => [
                $phpBinary,
                $artisan,
                'queue:work',
                '--queue=default,sync',
                '--sleep=3',
                '--tries=3',
                '--max-time=3600',
                '--no-interaction',
            ],
            self::Scheduler => [
                $phpBinary,
                $artisan,
                'schedule:work',
                '--no-interaction',
            ],
            self::Migrator => [
                $phpBinary,
                $artisan,
                'migrate',
                '--force',
                '--no-interaction',
            ],
            self::Bootstrap => [
                $phpBinary,
                $artisan,
                'db:seed',
                '--force',
                '--no-interaction',
            ],
            self::TenantEventConsumer => [
                $phpBinary,
                $artisan,
                'nexus:consume-tenant-events',
                '--no-interaction',
            ],
        };
    }

    public function shutdownSignal(): string
    {
        return $this === self::Web ? 'WINCH' : 'TERM';
    }

    public function isLongRunning(): bool
    {
        return match ($this) {
            self::Web, self::Queue, self::Scheduler, self::TenantEventConsumer => true,
            self::Migrator, self::Bootstrap => false,
        };
    }
}
