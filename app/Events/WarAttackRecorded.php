<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarAttackRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $attackId,
        public readonly int $warId,
    ) {}
}
