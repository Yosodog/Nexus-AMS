<?php

namespace App\Events;

use App\Models\WarCounter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when a counter is finalized and made active.
 */
class CounterFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly WarCounter $counter) {}
}
