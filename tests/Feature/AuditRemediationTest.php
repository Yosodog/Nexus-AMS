<?php

namespace Tests\Feature;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\AuditRule;
use App\Models\Nation;
use App\Models\User;
use App\Services\Audit\AuditRemediationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuditRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_acknowledge_and_snooze_own_finding_with_history(): void
    {
        Cache::forever('alliances:membership:ids', [777, 888]);
        $nation = Nation::factory()->create(['alliance_id' => 888, 'alliance_position' => 'MEMBER']);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        $result = $this->resultFor($nation);
        $service = app(AuditRemediationService::class);

        $service->acknowledge($user, $result, 'Working on this.');
        $service->snooze($user, $result->fresh(), 24);

        $result->rule()->update([
            'name' => 'Renamed after events were recorded',
            'priority' => AuditPriority::Low,
            'revision' => 4,
        ]);

        $this->assertDatabaseHas('audit_results', [
            'id' => $result->id,
            'acknowledged_by_user_id' => $user->id,
            'remediation_note' => 'Working on this.',
        ]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $result->id,
            'event_type' => 'acknowledged',
            'actor_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $result->id,
            'event_type' => 'snoozed',
            'actor_user_id' => $user->id,
        ]);

        $events = AuditResultEvent::query()
            ->where('audit_result_id', $result->id)
            ->whereIn('event_type', ['acknowledged', 'snoozed'])
            ->get();

        $this->assertCount(2, $events);

        foreach ($events as $event) {
            $this->assertSame([
                'name' => 'Military readiness',
                'priority' => AuditPriority::High->value,
                'revision' => 3,
                'summary' => 'Alert when all of: Score is greater than 1,000 score.',
            ], $event->metadata['rule_snapshot']);
            $this->assertArrayNotHasKey('expression', $event->metadata['rule_snapshot']);
            $this->assertStringNotContainsString('expression', json_encode($event->metadata, JSON_THROW_ON_ERROR));
        }

        $this->assertTrue($result->fresh()->isSnoozed());
    }

    public function test_non_member_and_other_member_cannot_manage_a_finding(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $ownerNation = Nation::factory()->create(['alliance_id' => 777]);
        $result = $this->resultFor($ownerNation);
        $outsiderNation = Nation::factory()->create(['alliance_id' => 999]);
        $outsider = User::factory()->verified()->create(['nation_id' => $outsiderNation->id]);

        $this->expectException(AuthorizationException::class);
        app(AuditRemediationService::class)->acknowledge($outsider, $result);
    }

    private function resultFor(Nation $nation): AuditResult
    {
        $rule = AuditRule::query()->create([
            'name' => 'Military readiness',
            'description' => 'Restore required readiness.',
            'remediation_guidance' => 'Raise the nation score above the configured threshold.',
            'admin_notes' => 'Readiness policy maintained by the audit team.',
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::High,
            'definition' => $this->definition(),
            'revision' => 3,
            'enabled' => true,
        ]);

        return AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'rule_revision' => 3,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$nation->id,
            'nation_id' => $nation->id,
            'details' => [
                'rule_revision' => 3,
                'summary' => 'Alert when score is greater than 1,000 score.',
                'evidence' => [],
                'evaluated_at' => now()->toIso8601String(),
            ],
            'first_detected_at' => now(),
            'last_evaluated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
                'rules' => [[
                    'id' => 'c33ff733-2e57-4550-9226-4d66ef40cddd',
                    'field' => 'nation.score',
                    'operator' => 'gt',
                    'value' => 1000,
                ]],
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];
    }
}
