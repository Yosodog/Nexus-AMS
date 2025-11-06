<?php

namespace App\Events;

use App\Models\WarPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when a war plan transitions into the active state.
 */
class WarPlanActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly WarPlan $plan) {}
}
