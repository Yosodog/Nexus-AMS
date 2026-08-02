<?php

namespace Tests\Feature;

use App\Enums\AuditTargetType;
use App\Services\Audit\AuditRuleDefinitionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AuditRuleMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->createPreMigrationTables();
    }

    public function test_it_converts_representative_rules_and_preserves_successful_data(): void
    {
        $createdAt = '2026-06-01 12:34:56';
        $updatedAt = '2026-07-02 03:04:05';
        $cases = [
            101 => [
                'target' => 'nation',
                'expression' => 'nation.score > 1000 && (nation.color == "BLUE" || nation.num_cities >= 10)',
                'expected' => [
                    ['field' => 'nation.score', 'operator' => 'gt', 'value' => 1000],
                    ['field' => 'nation.color', 'operator' => 'eq', 'value' => 'BLUE'],
                    ['field' => 'nation.num_cities', 'operator' => 'gte', 'value' => 10],
                ],
            ],
            102 => [
                'target' => 'nation',
                'expression' => '50 > nation.aircraft',
                'expected' => [
                    ['field' => 'nation.aircraft', 'operator' => 'lt', 'value' => 50],
                ],
            ],
            103 => [
                'target' => 'nation',
                'expression' => 'nation.has_project("Urban Planning")',
                'expected' => [
                    ['field' => 'nation.projects', 'operator' => 'contains_any', 'value' => ['Urban Planning']],
                ],
            ],
            104 => [
                'target' => 'city',
                'expression' => 'city.improvements_count() > 20',
                'expected' => [
                    ['field' => 'city.improvement_count', 'operator' => 'gt', 'value' => 20],
                ],
            ],
            105 => [
                'target' => 'city',
                'expression' => 'city.infrastructure % 50 == 0',
                'expected' => [
                    ['field' => 'city.infrastructure', 'operator' => 'multiple_of', 'value' => 50],
                ],
            ],
            106 => [
                'target' => 'nation',
                'expression' => 'nation.aircraft < nation.num_cities * 50',
                'expected' => [
                    ['field' => 'nation.aircraft_per_city', 'operator' => 'lt', 'value' => 50],
                ],
            ],
            107 => [
                'target' => 'city',
                'expression' => 'city.improvements_count() > city.infrastructure / 50',
                'expected' => [
                    ['field' => 'city.improvement_capacity_exceeded', 'operator' => 'is_true'],
                ],
            ],
            108 => [
                'target' => 'nation',
                'expression' => 'nation.last_active < 1704067200',
                'expected' => [
                    ['field' => 'nation.last_activity', 'operator' => 'before', 'value' => '2024-01-01T00:00:00+00:00'],
                ],
            ],
        ];

        foreach ($cases as $id => $case) {
            $this->insertRule(
                id: $id,
                target: $case['target'],
                expression: $case['expression'],
                createdAt: $createdAt,
                updatedAt: $updatedAt,
            );
        }

        DB::table('audit_results')->insert([
            'id' => 801,
            'audit_rule_id' => 101,
            'target_type' => 'nation',
            'target_key' => 'nation:7001',
            'nation_id' => 7001,
            'city_id' => null,
            'details' => json_encode(['legacy' => 'evidence'], JSON_THROW_ON_ERROR),
            'first_detected_at' => '2026-06-05 01:02:03',
            'last_evaluated_at' => '2026-07-01 04:05:06',
            'acknowledged_at' => '2026-07-01 05:00:00',
            'acknowledged_by_user_id' => 55,
            'snoozed_until' => null,
            'snoozed_by_user_id' => null,
            'waived_until' => null,
            'waived_by_user_id' => null,
            'due_at' => '2026-07-10 00:00:00',
            'remediation_note' => 'Existing remediation note',
            'created_at' => '2026-06-05 01:02:03',
            'updated_at' => '2026-07-01 05:00:00',
        ]);
        DB::table('audit_result_events')->insert([
            'id' => 901,
            'audit_result_id' => 801,
            'audit_rule_id' => 101,
            'target_type' => 'nation',
            'target_key' => 'nation:7001',
            'nation_id' => 7001,
            'city_id' => null,
            'actor_user_id' => 55,
            'event_type' => 'acknowledged',
            'metadata' => json_encode(['note' => 'Existing history'], JSON_THROW_ON_ERROR),
            'occurred_at' => '2026-07-01 05:00:00',
            'created_at' => '2026-07-01 05:00:00',
            'updated_at' => '2026-07-01 05:00:00',
        ]);

        $this->migration()->up();

        foreach ($cases as $id => $case) {
            $rule = DB::table('audit_rules')->where('id', $id)->first();

            $this->assertNotNull($rule);
            $this->assertSame(1, (int) $rule->enabled);
            $this->assertSame(1, (int) $rule->revision);
            $this->assertSame('never_run', $rule->last_evaluation_status);

            $definition = json_decode((string) $rule->definition, true, flags: JSON_THROW_ON_ERROR);
            $conditions = $this->conditions($definition['criteria']);

            foreach ($case['expected'] as $expectedCondition) {
                $matchingConditions = array_filter(
                    $conditions,
                    static fn (array $condition): bool => array_intersect_key($condition, $expectedCondition) === $expectedCondition,
                );

                $this->assertNotEmpty($matchingConditions, "Rule {$id} did not contain the expected converted condition.");
            }
        }

        $preservedRule = DB::table('audit_rules')->where('id', 101)->first();
        $this->assertSame('Rule 101', $preservedRule->name);
        $this->assertSame('Description for rule 101', $preservedRule->description);
        $this->assertSame('high', $preservedRule->priority);
        $this->assertSame(41, (int) $preservedRule->created_by);
        $this->assertSame(42, (int) $preservedRule->updated_by);
        $this->assertSame($createdAt, $preservedRule->created_at);
        $this->assertSame($updatedAt, $preservedRule->updated_at);

        $preservedResult = DB::table('audit_results')->where('id', 801)->first();
        $this->assertNotNull($preservedResult);
        $this->assertSame(101, (int) $preservedResult->audit_rule_id);
        $this->assertSame(1, (int) $preservedResult->rule_revision);
        $this->assertSame('2026-06-05 01:02:03', $preservedResult->first_detected_at);
        $this->assertSame('Existing remediation note', $preservedResult->remediation_note);

        $preservedEvent = DB::table('audit_result_events')->where('id', 901)->first();
        $this->assertNotNull($preservedEvent);
        $this->assertSame('acknowledged', $preservedEvent->event_type);
        $this->assertSame(801, (int) $preservedEvent->audit_result_id);

        $this->assertFalse(Schema::hasColumn('audit_rules', 'expression'));
        $this->assertTrue(Schema::hasColumn('audit_rules', 'definition'));
        $this->assertTrue(Schema::hasColumn('audit_results', 'rule_revision'));
        $this->assertEveryEnabledRuleHasAValidDefinition();
    }

    public function test_it_disables_unsupported_rules_logs_failures_and_closes_findings_without_aborting(): void
    {
        $malformedExpression = 'nation.score >';
        $arithmeticExpression = 'nation.score + 10 > 100';

        $this->insertRule(201, 'nation', $malformedExpression);
        $this->insertRule(202, 'nation', $arithmeticExpression);
        $this->insertRule(203, 'nation', 'nation.num_cities >= 12');
        $this->insertResult(811, 201, 'nation:7101', 7101);
        $this->insertResult(812, 202, 'nation:7102', 7102);

        Log::spy();

        $this->migration()->up();

        foreach ([201, 202] as $failedRuleId) {
            $failedRule = DB::table('audit_rules')->where('id', $failedRuleId)->first();

            $this->assertNotNull($failedRule);
            $this->assertSame(0, (int) $failedRule->enabled);
            $this->assertNull($failedRule->definition);
            $this->assertSame('migration_failed', $failedRule->last_evaluation_status);
            $this->assertSame(0, (int) $failedRule->last_match_count);
            $this->assertSame(
                'This imported rule could not be converted safely. Rebuild it with the guided rule editor.',
                $failedRule->last_evaluation_error,
            );
        }

        $this->assertDatabaseMissing('audit_results', ['id' => 811]);
        $this->assertDatabaseMissing('audit_results', ['id' => 812]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => 811,
            'audit_rule_id' => 201,
            'event_type' => 'migration_disabled',
        ]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => 812,
            'audit_rule_id' => 202,
            'event_type' => 'migration_disabled',
        ]);

        $convertedRule = DB::table('audit_rules')->where('id', 203)->first();
        $this->assertSame(1, (int) $convertedRule->enabled);
        $this->assertNotNull($convertedRule->definition);

        $this->assertStructuredFailureWasLogged(201, 'Rule 201', 'nation', $malformedExpression, 'syntax_error');
        $this->assertStructuredFailureWasLogged(202, 'Rule 202', 'nation', $arithmeticExpression, 'arbitrary_arithmetic');

        $databaseState = DB::table('audit_rules')->get()->toJson()
            .DB::table('audit_result_events')->get()->toJson();
        $this->assertStringNotContainsString($malformedExpression, $databaseState);
        $this->assertStringNotContainsString($arithmeticExpression, $databaseState);
        $this->assertFalse(Schema::hasColumn('audit_rules', 'expression'));
        $this->assertEveryEnabledRuleHasAValidDefinition();
    }

    public function test_down_is_explicitly_irreversible(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore the pre-deployment database backup');

        $this->migration()->down();
    }

    private function createPreMigrationTables(): void
    {
        Schema::dropIfExists('audit_result_events');
        Schema::dropIfExists('audit_results');
        Schema::dropIfExists('audit_rules');

        Schema::create('audit_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('target_type');
            $table->string('priority');
            $table->text('expression');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'target_type'], 'audit_rules_enabled_target_idx');
        });

        Schema::create('audit_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('audit_rule_id');
            $table->string('target_type');
            $table->string('target_key')->nullable();
            $table->unsignedBigInteger('nation_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_evaluated_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->unsignedBigInteger('snoozed_by_user_id')->nullable();
            $table->timestamp('waived_until')->nullable();
            $table->unsignedBigInteger('waived_by_user_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('remediation_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['audit_rule_id', 'target_type', 'target_key'], 'audit_results_rule_target_unique');
        });

        Schema::create('audit_result_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('audit_result_id')->nullable();
            $table->unsignedBigInteger('audit_rule_id')->nullable();
            $table->string('target_type', 32);
            $table->string('target_key', 191);
            $table->unsignedBigInteger('nation_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    private function insertRule(
        int $id,
        string $target,
        string $expression,
        string $createdAt = '2026-06-01 00:00:00',
        string $updatedAt = '2026-06-02 00:00:00',
    ): void {
        DB::table('audit_rules')->insert([
            'id' => $id,
            'name' => "Rule {$id}",
            'description' => "Description for rule {$id}",
            'target_type' => $target,
            'priority' => 'high',
            'expression' => $expression,
            'enabled' => true,
            'created_by' => 41,
            'updated_by' => 42,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function insertResult(int $id, int $ruleId, string $targetKey, int $nationId): void
    {
        DB::table('audit_results')->insert([
            'id' => $id,
            'audit_rule_id' => $ruleId,
            'target_type' => 'nation',
            'target_key' => $targetKey,
            'nation_id' => $nationId,
            'city_id' => null,
            'details' => null,
            'first_detected_at' => '2026-06-05 01:02:03',
            'last_evaluated_at' => '2026-07-01 04:05:06',
            'acknowledged_at' => null,
            'acknowledged_by_user_id' => null,
            'snoozed_until' => null,
            'snoozed_by_user_id' => null,
            'waived_until' => null,
            'waived_by_user_id' => null,
            'due_at' => null,
            'remediation_note' => null,
            'created_at' => '2026-06-05 01:02:03',
            'updated_at' => '2026-07-01 04:05:06',
        ]);
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<array<string, mixed>>
     */
    private function conditions(array $group): array
    {
        $conditions = [];

        foreach ($group['rules'] as $rule) {
            if (isset($rule['group'])) {
                $conditions = [...$conditions, ...$this->conditions($rule)];

                continue;
            }

            unset($rule['id']);
            $conditions[] = $rule;
        }

        return $conditions;
    }

    private function assertEveryEnabledRuleHasAValidDefinition(): void
    {
        $definitionService = app(AuditRuleDefinitionService::class);

        DB::table('audit_rules')->where('enabled', true)->orderBy('id')->get()->each(
            function (object $rule) use ($definitionService): void {
                $definition = json_decode((string) $rule->definition, true, flags: JSON_THROW_ON_ERROR);
                $target = AuditTargetType::from((string) $rule->target_type);
                $inspection = $definitionService->inspect($definition, $target);

                $this->assertNotNull($rule->definition);
                $this->assertSame([], $inspection['errors']);
                $this->assertNotNull($inspection['normalized']);
                $this->assertTrue($definitionService->hasCriteria($inspection['normalized']));
            },
        );
    }

    private function assertStructuredFailureWasLogged(
        int $ruleId,
        string $ruleName,
        string $target,
        string $expression,
        string $reasonCode,
    ): void {
        Log::shouldHaveReceived('warning')->with(
            'Legacy audit rule conversion failed',
            Mockery::on(static fn (array $context): bool => $context['event'] === 'audit_rule_migration_failed'
                && $context['rule_id'] === $ruleId
                && $context['rule_name'] === $ruleName
                && $context['target_type'] === $target
                && $context['original_expression'] === $expression
                && $context['reason_code'] === $reasonCode
                && is_string($context['reason'])
                && $context['reason'] !== ''),
        )->once();
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_02_171545_replace_nel_audit_rules_with_rule_trees.php');

        return $migration;
    }
}
