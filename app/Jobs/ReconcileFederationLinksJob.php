<?php

namespace App\Jobs;

use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Services\FederationReconciliationService;
use App\Models\FederationIdentity;
use App\Models\FederationLink;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileFederationLinksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(FederationReconciliationService $reconciliation): void
    {
        if (! (bool) config('federation.enabled', false)
            || ! (bool) config('federation.features.inbound', false)
            || ! FederationIdentity::query()->where('enabled', true)->exists()) {
            return;
        }

        FederationLink::query()
            ->where('status', FederationLinkStatus::Active->value)
            ->orderBy('id')
            ->eachById(fn (FederationLink $link) => $reconciliation->send($link), 100);
    }
}
