<?php

namespace App\Jobs;

use App\Models\WarNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches stored war notifications to their downstream transport layers.
 *
 * At present we only log the payload to keep audit trails consistent. When the Discord bot and PW
 * mail transport are ready, integrate them here.
 */
class DispatchWarNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        /** @var WarNotification|null $notification */
        $notification = WarNotification::query()->find($this->notificationId);

        if (! $notification || $notification->status !== 'pending') {
            return;
        }

        // Rationale: Divergence from spec – real transports not yet implemented. Logging keeps observability.
        Log::info('Dispatching war notification (placeholder)', [
            'notification_id' => $notification->id,
            'event_type' => $notification->event_type,
            'payload' => $notification->payload,
        ]);

        $notification->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
