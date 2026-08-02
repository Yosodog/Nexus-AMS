<?php

namespace Tests\Feature\Jobs;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Jobs\RunAuditRuleJob;
use App\Jobs\RunAuditsJob;
use App\Models\AuditRule;
use App\Services\Audit\AuditService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RunAuditRuleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_per_rule_and_global_jobs_have_distinct_unique_queue_contracts(): void
    {
        $ruleJob = new RunAuditRuleJob(42);

        $this->assertInstanceOf(ShouldQueue::class, $ruleJob);
        $this->assertInstanceOf(ShouldBeUnique::class, $ruleJob);
        $this->assertSame('audits:rule:42', $ruleJob->uniqueId());
        $this->assertSame(5400, $ruleJob->uniqueFor);
        $this->assertSame(10, $ruleJob->tries);

        $middleware = $ruleJob->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('audits:rule:42', $middleware[0]->key);
        $this->assertSame(60, $middleware[0]->releaseAfter);
        $this->assertSame(5400, $middleware[0]->expiresAfter);

        $globalJob = new RunAuditsJob;

        $this->assertInstanceOf(ShouldQueue::class, $globalJob);
        $this->assertInstanceOf(ShouldBeUnique::class, $globalJob);
        $this->assertSame('audits:run', $globalJob->uniqueId());
        $this->assertSame(5400, $globalJob->uniqueFor);
        $this->assertNotSame($globalJob->uniqueId(), $ruleJob->uniqueId());
    }

    public function test_job_ignores_disabled_and_deleted_rules(): void
    {
        $disabledRule = $this->createRule(enabled: false);
        $disabledJob = (new RunAuditRuleJob($disabledRule->id))->withFakeQueueInteractions();

        $disabledJob->handle(app(AuditService::class));

        $disabledRule->refresh();
        $disabledJob->assertNotReleased();
        $this->assertSame(AuditEvaluationStatus::NeverRun, $disabledRule->last_evaluation_status);
        $this->assertNull($disabledRule->last_evaluated_at);

        $deletedRule = $this->createRule(enabled: true);
        $deletedRuleId = $deletedRule->id;
        $deletedRule->delete();
        $deletedJob = (new RunAuditRuleJob($deletedRuleId))->withFakeQueueInteractions();

        $deletedJob->handle(app(AuditService::class));

        $deletedJob->assertNotReleased();
        $this->assertNull(AuditRule::query()->find($deletedRuleId));
    }

    public function test_job_invokes_audit_service_for_an_enabled_rule(): void
    {
        $rule = $this->createRule(enabled: true);
        $job = (new RunAuditRuleJob($rule->id))->withFakeQueueInteractions();

        $job->handle(app(AuditService::class));

        $rule->refresh();
        $job->assertNotReleased();
        $this->assertSame(AuditEvaluationStatus::Success, $rule->last_evaluation_status);
        $this->assertNotNull($rule->last_evaluated_at);
        $this->assertSame(0, $rule->last_match_count);
    }

    public function test_per_rule_job_releases_when_the_global_audit_lock_is_held(): void
    {
        $rule = $this->createRule(enabled: true);
        $job = (new RunAuditRuleJob($rule->id))->withFakeQueueInteractions();
        $lock = Cache::lock('audits:run', 5400);

        $this->assertTrue($lock->get());

        try {
            $job->handle(app(AuditService::class));

            $job->assertReleased(60);
            $rule->refresh();
            $this->assertSame(AuditEvaluationStatus::NeverRun, $rule->last_evaluation_status);
            $this->assertNull($rule->last_evaluated_at);
        } finally {
            $lock->release();
        }
    }

    public function test_global_job_coexists_with_the_shared_audit_lock_without_running_rules_twice(): void
    {
        $rule = $this->createRule(enabled: true);
        $lock = Cache::lock('audits:run', 5400);

        $this->assertTrue($lock->get());

        try {
            (new RunAuditsJob)->handle(app(AuditService::class));

            $rule->refresh();
            $this->assertSame(AuditEvaluationStatus::NeverRun, $rule->last_evaluation_status);
            $this->assertNull($rule->last_evaluated_at);
        } finally {
            $lock->release();
        }
    }

    private function createRule(bool $enabled): AuditRule
    {
        return AuditRule::query()->create([
            'name' => 'Score threshold',
            'description' => 'Keep score above the alliance minimum.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'definition' => [
                'schema_version' => 1,
                'criteria' => [
                    'group' => 'all',
                    'rules' => [[
                        'id' => '10000000-0000-4000-8000-000000000001',
                        'field' => 'nation.score',
                        'operator' => 'lt',
                        'value' => 1000,
                    ]],
                ],
                'exceptions' => [
                    'group' => 'any',
                    'rules' => [],
                ],
            ],
            'revision' => 1,
            'enabled' => $enabled,
            'last_evaluation_status' => AuditEvaluationStatus::NeverRun,
        ]);
    }
}
