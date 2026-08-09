<?php

namespace App\Jobs;

use App\Domain\Federation\Services\FederationExpiryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireFederationResourcesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(FederationExpiryService $expiry): void
    {
        if ((bool) config('federation.enabled', false)) {
            $expiry->run();
        }
    }
}
