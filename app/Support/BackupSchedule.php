<?php

namespace App\Support;

use App\Services\Backups\BackupSettings;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves the UTC cron expression for scheduled backups.
 *
 * The admin setting wins, then `backup.schedule.cron` (BACKUP_SCHEDULE_CRON), then
 * the default. An invalid value falls back to the default, because an unparsable
 * expression would make every `schedule:run` tick throw and stop all scheduled tasks.
 */
final class BackupSchedule
{
    public const DEFAULT_CRON = '30 1,7,13,19 * * *';

    /** @var array<string, string> */
    public const PRESETS = [
        'every_6_hours' => self::DEFAULT_CRON,
        'every_12_hours' => '30 1,13 * * *',
        'daily' => '30 1 * * *',
    ];

    public static function expression(): string
    {
        $configured = self::storedExpression() ?? trim((string) config('backup.schedule.cron', ''));

        if ($configured === '') {
            return self::DEFAULT_CRON;
        }

        if (! self::isValid($configured)) {
            Log::warning('Invalid backup schedule cron expression; using the default.', [
                'configured' => $configured,
                'default' => self::DEFAULT_CRON,
            ]);

            return self::DEFAULT_CRON;
        }

        return $configured;
    }

    public static function isValid(string $expression): bool
    {
        return CronExpression::isValidExpression(trim($expression));
    }

    public static function presetFor(string $expression): string
    {
        return array_search($expression, self::PRESETS, true) ?: 'custom';
    }

    public static function nextRunAt(string $expression, ?DateTimeInterface $from = null): Carbon
    {
        return Carbon::instance((new CronExpression($expression))->getNextRunDate($from ?? now('UTC'), 0, false, 'UTC'));
    }

    public static function previousRunAt(string $expression, ?DateTimeInterface $from = null): Carbon
    {
        return Carbon::instance((new CronExpression($expression))->getPreviousRunDate($from ?? now('UTC'), 0, true, 'UTC'));
    }

    private static function storedExpression(): ?string
    {
        try {
            $stored = app(BackupSettings::class)->storedScheduleCron();
        } catch (Throwable) {
            return null;
        }

        return filled($stored) ? trim((string) $stored) : null;
    }
}
