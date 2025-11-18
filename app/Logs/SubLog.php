<?php

namespace App\Logs;

use Carbon\Carbon;
use Opcodes\LogViewer\Logs\Log;

class SubLog extends Log
{
    public static string $name = 'Subscription Service';
    public static string $levelClass = SubLogLevel::class;

    public static string $regex =
        '/^(?<datetime>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z)\s+(?<level>[A-Z]+)\s+(?<message>[^|]+?)(?:\s*\|\s*(?<meta>.*))?$/m';

    public static array $columns = [
        ['label' => 'Time', 'data_path' => 'datetime'],
        ['label' => 'Level', 'data_path' => 'level'],
        ['label' => 'Message', 'data_path' => 'message'],
        ['label' => 'Model', 'data_path' => 'context.model'],
        ['label' => 'Event', 'data_path' => 'context.event'],
    ];

    public function fillMatches(array $matches = []): void
    {
        // Timestamp
        $this->datetime = Carbon::parse($matches['datetime']);

        // Message
        $this->message = trim($matches['message']);

        // Level
        $this->level = strtolower($matches['level']);

        // Metadata parsing
        $metaString = $matches['meta'] ?? '';
        $context = [];

        if ($metaString) {
            preg_match_all('/(\w+)=("[^"]+"|\S+)/', $metaString, $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $key = $pair[1];
                $val = trim($pair[2], '"');
                $context[$key] = $val;
            }
        }

        $this->context = $context;
    }
}
