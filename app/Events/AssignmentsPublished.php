<?php

namespace App\Events;

use App\Models\WarPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted after plan assignments are published to members.
 */
class AssignmentsPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly WarPlan $plan) {}
}
