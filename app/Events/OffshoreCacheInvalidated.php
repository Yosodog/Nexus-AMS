<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OffshoreCacheInvalidated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly ?int $offshoreId,
        public readonly string $reason
    ) {
        // The payload intentionally stays small because broadcasts are consumed by lightweight listeners.
    }

    public function broadcastOn(): Channel
    {
        return new Channel('offshores');
    }

    public function broadcastWith(): array
    {
        return [
            'offshore_id' => $this->offshoreId,
            'reason' => $this->reason,
        ];
    }

    public function broadcastAs(): string
    {
        return 'OffshoreCacheInvalidated';
    }
}
