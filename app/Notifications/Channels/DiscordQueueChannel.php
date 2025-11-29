<?php

namespace App\Notifications\Channels;

use App\Services\Discord\DiscordQueueService;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DiscordQueueChannel
{
    public function __construct(private readonly DiscordQueueService $queueService) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDiscordBot')) {
            Log::warning('Notification missing toDiscordBot payload for Discord queue', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $message = $notification->toDiscordBot($notifiable);

        if (! is_array($message)) {
            Log::warning('Discord bot payload must be an array', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $action = $message['action'] ?? null;
        $payload = $message['payload'] ?? null;
        $availableAt = $message['available_at'] ?? null;

        if (! is_string($action) || $action === '' || ! is_array($payload)) {
            Log::warning('Discord bot payload missing action or payload', [
                'notification' => $notification::class,
                'action_present' => $action !== null,
                'payload_type' => get_debug_type($payload),
            ]);

            return;
        }

        if (! array_key_exists('channel_id', $payload) && isset($message['channel_id'])) {
            $payload['channel_id'] = $message['channel_id'];
        }

        $availableTimestamp = null;

        if ($availableAt instanceof CarbonInterface) {
            $availableTimestamp = Carbon::instance($availableAt);
        } elseif (is_string($availableAt)) {
            try {
                $availableTimestamp = Carbon::parse($availableAt);
            } catch (\Exception $exception) {
                Log::warning('Unable to parse available_at for Discord queue payload', [
                    'notification' => $notification::class,
                    'available_at' => $availableAt,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->queueService->enqueue($action, $payload, $availableTimestamp);
    }
}
