<?php

namespace Tests\Feature\Migrations;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\AuditRule;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseStaleFindingsForDisabledAuditRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_closes_disabled_rule_findings_and_preserves_current_findings(): void
    {
        $nation = Nation::factory()->create();
        $disabledRule = $this->createRule('Disabled rule', enabled: false);
        $enabledRule = $this->createRule('Enabled rule', enabled: true);
        $disabledFinding = $this->createFinding($disabledRule, $nation);
        $enabledFinding = $this->createFinding($enabledRule, $nation);

        $this->migration()->up();

        $this->assertDatabaseMissing('audit_results', ['id' => $disabledFinding->id]);
        $this->assertDatabaseHas('audit_results', ['id' => $enabledFinding->id]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => $disabledFinding->id,
            'audit_rule_id' => $disabledRule->id,
            'event_type' => 'rule_disabled',
            'actor_user_id' => null,
        ]);
        $this->assertDatabaseHas('audit_rules', [
            'id' => $disabledRule->id,
            'last_match_count' => 0,
        ]);
        $this->assertDatabaseHas('audit_rules', [
            'id' => $enabledRule->id,
            'last_match_count' => 1,
        ]);

        $event = AuditResultEvent::query()->where('audit_result_id', $disabledFinding->id)->sole();
        $this->assertSame('disabled_rule_cleanup', $event->metadata['reason']);
        $this->assertSame('Disabled rule', $event->metadata['rule_snapshot']['name']);

        $this->migration()->up();

        $this->assertSame(1, AuditResultEvent::query()->where('audit_result_id', $disabledFinding->id)->count());
    }

    private function createRule(string $name, bool $enabled): AuditRule
    {
        return AuditRule::query()->create([
            'name' => $name,
            'target_type' => AuditTargetType::Nation,
            'priority' => AuditPriority::Medium,
            'definition' => [
                'schema_version' => 1,
                'criteria' => [
                    'group' => 'all',
                    'rules' => [],
                ],
                'exceptions' => [
                    'group' => 'any',
                    'rules' => [],
                ],
            ],
            'revision' => 2,
            'enabled' => $enabled,
            'last_match_count' => 1,
        ]);
    }

    private function createFinding(AuditRule $rule, Nation $nation): AuditResult
    {
        return AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'rule_revision' => 2,
            'target_type' => AuditTargetType::Nation,
            'target_key' => 'nation:'.$nation->id,
            'nation_id' => $nation->id,
            'first_detected_at' => now()->subDay(),
            'last_evaluated_at' => now(),
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_14_013641_close_stale_findings_for_disabled_audit_rules.php');
    }
}
