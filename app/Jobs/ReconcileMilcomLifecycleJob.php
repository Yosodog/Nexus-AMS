<?php

namespace App\Jobs;

use App\Services\Milcom\LifecycleReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileMilcomLifecycleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 55;

    public int $timeout = 45;

    public function uniqueId(): string
    {
        return 'milcom-lifecycle-safety';
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['milcom:lifecycle'];
    }

    public function handle(LifecycleReconciler $lifecycle): void
    {
        if ((bool) config('milcom.v2_enabled', false)) {
            $lifecycle->reconcileAll();
        }
    }
}
