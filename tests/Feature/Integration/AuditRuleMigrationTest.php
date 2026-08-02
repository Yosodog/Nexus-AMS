<?php

namespace Tests\Feature\Integration;

use App\Enums\AuditTargetType;
use App\Services\Audit\AuditRuleDefinitionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Integration\MySqlIntegrationTestCase;

class AuditRuleMigrationTest extends MySqlIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('audit_result_events');
        Schema::dropIfExists('audit_results');
        Schema::dropIfExists('audit_rules');
        Schema::enableForeignKeyConstraints();

        $this->createPreMigrationTables();
    }

    public function test_mysql_migration_converts_json_and_preserves_or_closes_findings_correctly(): void
    {
        $legacyExpression = 'nation.score + 5 > 1000';

        DB::table('audit_rules')->insert([
            [
                'id' => 301,
                'name' => 'MySQL converted rule',
                'description' => 'Preserved member explanation',
                'target_type' => 'nation',
                'priority' => 'medium',
                'expression' => 'nation.score >= 1000 && nation.has_project("Urban Planning")',
                'enabled' => true,
                'created_by' => 71,
                'updated_by' => 72,
                'created_at' => '2026-06-10 10:11:12',
                'updated_at' => '2026-07-10 11:12:13',
            ],
            [
                'id' => 302,
                'name' => 'MySQL failed rule',
                'description' => 'Must be rebuilt',
                'target_type' => 'nation',
                'priority' => 'high',
                'expression' => $legacyExpression,
                'enabled' => true,
                'created_by' => 73,
                'updated_by' => 74,
                'created_at' => '2026-06-11 10:11:12',
                'updated_at' => '2026-07-11 11:12:13',
            ],
        ]);

        $this->insertResult(821, 301, 'nation:7201', 7201);
        $this->insertResult(822, 302, 'nation:7202', 7202);
        DB::table('audit_result_events')->insert([
            'id' => 921,
            'audit_result_id' => 821,
            'audit_rule_id' => 301,
            'target_type' => 'nation',
            'target_key' => 'nation:7201',
            'nation_id' => 7201,
            'city_id' => null,
            'actor_user_id' => 71,
            'event_type' => 'acknowledged',
            'metadata' => json_encode(['state' => 'preserved'], JSON_THROW_ON_ERROR),
            'occurred_at' => '2026-07-01 05:00:00',
            'created_at' => '2026-07-01 05:00:00',
            'updated_at' => '2026-07-01 05:00:00',
        ]);

        Log::spy();

        $this->migration()->up();

        $convertedRule = DB::table('audit_rules')->where('id', 301)->first();
        $definition = json_decode((string) $convertedRule->definition, true, flags: JSON_THROW_ON_ERROR);
        $inspection = app(AuditRuleDefinitionService::class)->inspect($definition, AuditTargetType::Nation);

        $this->assertSame([], $inspection['errors']);
        $this->assertTrue(app(AuditRuleDefinitionService::class)->hasCriteria($inspection['normalized']));
        $this->assertSame(1, (int) $convertedRule->enabled);
        $this->assertSame(71, (int) $convertedRule->created_by);
        $this->assertSame('2026-06-10 10:11:12', $convertedRule->created_at);
        $this->assertDatabaseHas('audit_results', [
            'id' => 821,
            'audit_rule_id' => 301,
            'rule_revision' => 1,
        ]);
        $this->assertDatabaseHas('audit_result_events', [
            'id' => 921,
            'audit_result_id' => 821,
            'event_type' => 'acknowledged',
        ]);

        $failedRule = DB::table('audit_rules')->where('id', 302)->first();
        $this->assertSame(0, (int) $failedRule->enabled);
        $this->assertNull($failedRule->definition);
        $this->assertSame('migration_failed', $failedRule->last_evaluation_status);
        $this->assertDatabaseMissing('audit_results', ['id' => 822]);
        $this->assertDatabaseHas('audit_result_events', [
            'audit_result_id' => 822,
            'audit_rule_id' => 302,
            'event_type' => 'migration_disabled',
        ]);

        Log::shouldHaveReceived('warning')->with(
            'Legacy audit rule conversion failed',
            Mockery::on(static fn (array $context): bool => $context['rule_id'] === 302
                && $context['rule_name'] === 'MySQL failed rule'
                && $context['target_type'] === 'nation'
                && $context['original_expression'] === $legacyExpression
                && $context['reason_code'] === 'arbitrary_arithmetic'
                && is_string($context['reason'])
                && $context['reason'] !== ''),
        )->once();

        $this->assertFalse(Schema::hasColumn('audit_rules', 'expression'));
        $this->assertSame(0, DB::table('audit_rules')->where('enabled', true)->whereNull('definition')->count());
        $this->assertStringNotContainsString(
            $legacyExpression,
            DB::table('audit_rules')->get()->toJson().DB::table('audit_result_events')->get()->toJson(),
        );
    }

    public function test_mysql_down_is_explicitly_irreversible(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore the pre-deployment database backup');

        $this->migration()->down();
    }

    private function createPreMigrationTables(): void
    {
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

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_02_171545_replace_nel_audit_rules_with_rule_trees.php');

        return $migration;
    }
}
