<?php

namespace App\Jobs;

use App\Models\AuditRule;
use App\Services\Audit\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class RunAuditRuleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $uniqueFor = 5400;

    public function __construct(public readonly int $auditRuleId) {}

    public function handle(AuditService $auditService): void
    {
        $rule = AuditRule::query()->find($this->auditRuleId);

        if ($rule === null || ! $rule->enabled) {
            return;
        }

        if (! $auditService->runRule($rule)) {
            $this->release(60);
        }
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(60)
                ->expireAfter(5400),
        ];
    }

    public function uniqueId(): string
    {
        return "audits:rule:{$this->auditRuleId}";
    }
}
