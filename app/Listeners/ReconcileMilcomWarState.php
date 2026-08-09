<?php

namespace App\Listeners;

use App\Events\WarAttackRecorded;
use App\Events\WarDeclared;
use App\Events\WarStateChanged;
use App\Services\Milcom\LifecycleReconciler;
use Illuminate\Support\Facades\Schema;

class ReconcileMilcomWarState
{
    public function __construct(private readonly LifecycleReconciler $lifecycle) {}

    public function handle(WarAttackRecorded|WarDeclared|WarStateChanged $event): void
    {
        if (! (bool) config('milcom.v2_enabled', true)) {
            return;
        }

        if (! Schema::hasTable('milcom_assignments')) {
            return;
        }

        if ($event instanceof WarAttackRecorded) {
            $this->lifecycle->recordAttack($event->attackId, $event->warId);

            return;
        }

        if ($event instanceof WarDeclared) {
            $this->lifecycle->reconcileDeclaration($event->warId);

            return;
        }

        $this->lifecycle->reconcileWar($event->warId);
    }
}
