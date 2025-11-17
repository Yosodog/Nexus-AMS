<?php

namespace App\Logs;

use Opcodes\LogViewer\Logs\Log;

class CronLog extends Log
{
    public static string $name = 'Cron';

    /**
     * Matches scheduler entries that look like:
     *   <optional spaces>2025-11-06 21:51:12 Running ['artisan' pw:health-check] ... DONE
     */
    public static string $regex =
        '/^\s*(?<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+Running\s+(?<command>\[[^\]]*\])\s+(?<message>.*)$/m';

    public static array $columns = [
        ['label' => 'Datetime', 'data_path' => 'datetime'],
        ['label' => 'Command', 'data_path' => 'context.command'],
        ['label' => 'Message', 'data_path' => 'message'],
    ];

    public function fillMatches(array $matches = []): void
    {
        parent::fillMatches($matches);

        $this->context = [
            'command' => $matches['command'] ?? null,
        ];
    }
}
