<?php

namespace App\Jobs;

use App\Services\Audit\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAuditsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 5400;

    public function handle(AuditService $auditService): void
    {
        $auditService->runAllEnabledRules();
    }

    public function uniqueId(): string
    {
        return 'audits:run';
    }
}
