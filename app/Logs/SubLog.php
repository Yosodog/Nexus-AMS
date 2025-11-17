<?php

namespace App\Logs;

use Carbon\Carbon;
use Opcodes\LogViewer\Logs\Log;

class SubLog extends Log
{
    public static string $name = 'Subscription Service';

    /**
     * This tells LogViewer to use SubLogLevel to interpret level strings.
     */
    public static string $levelClass = SubLogLevel::class;

    /**
     * Match every line in the log.
     */
    public static string $regex = '/^(?<message>.+)$/m';

    public static array $columns = [
        ['label' => 'Time', 'data_path' => 'datetime'],
        ['label' => 'Type', 'data_path' => 'level'],       // now level is the type string
        ['label' => 'Details', 'data_path' => 'message'],
    ];

    public function fillMatches(array $matches = []): void
    {
        $message = trim($matches['message'] ?? '');

        // REQUIRED: Carbon instance
        $this->datetime = Carbon::now();
        $this->message = $message;

        // Default
        $type = 'info';

        // ------------------------------------------------------------
        // 1. Received channel
        // ------------------------------------------------------------
        if (preg_match('/Received channel (?<channel>\S+) for (?<event>[\w:]+) \(attempt (?<attempt>\d+)\)/',
            $message, $m)) {

            // Split into resource + action
            [$resource, $action] = explode(':', $m['event']);

            // Create / Delete get promoted to top-level types
            if ($action === 'create') {
                $type = 'create';
            } elseif ($action === 'delete') {
                $type = 'delete';
            } else {
                $type = 'received-channel';
            }

            $this->context = [
                'type'     => $type,
                'resource' => $resource,
                'action'   => $action,
                'channel'  => $m['channel'],
                'attempt'  => (int) $m['attempt'],
            ];

            $this->level = $type;
            return;
        }

        // ------------------------------------------------------------
        // 2. Successfully subscribed
        // ------------------------------------------------------------
        if (preg_match('/Successfully subscribed to channel: (?<channel>\S+)/', $message, $m)) {

            $type = 'subscribed';

            $this->context = [
                'type'    => $type,
                'channel' => $m['channel'],
            ];

            $this->level = $type;
            return;
        }

        // ------------------------------------------------------------
        // 3. Successfully sent update
        // ------------------------------------------------------------
        if (preg_match('/Successfully sent update for (?<model>\w+) \((?<count>\d+) records\)/', $message, $m)) {

            $type = 'update-sent';

            $this->context = [
                'type'  => $type,
                'model' => $m['model'],
                'count' => (int) $m['count'],
            ];

            $this->level = $type;
            return;
        }

        // ------------------------------------------------------------
        // 4. Startup / Config / Shutdown / Misc
        // ------------------------------------------------------------
        if (str_contains($message, 'Starting subscription service')) {
            $type = 'startup';
        } elseif (str_contains($message, 'SIGTERM received')) {
            $type = 'shutdown';
        } elseif (str_contains($message, 'Snapshots disabled')) {
            $type = 'config';
        } elseif (str_contains($message, 'Connected to Pusher')) {
            $type = 'pusher-connected';
        }

        $this->context = [
            'type' => $type,
        ];

        // Assign type STRING — NOT an object
        $this->level = $type;
    }
}
