<?php

namespace Tests\Integration;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use App\Services\World\WorldModelManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HostedFreshMigrationTest extends TestCase
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const TENANT_WORLD_REFERENCES = [
        ['beige_alert_alliances', 'alliance_id'],
        ['milcom_operation_alliances', 'alliance_id'],
        ['offshores', 'alliance_id'],
        ['spy_campaign_alliances', 'alliance_id'],
        ['war_plan_alliances', 'alliance_id'],
        ['audit_result_events', 'city_id'],
        ['audit_results', 'city_id'],
        ['nation_build_recommendations', 'market_price_snapshot_id'],
        ['nation_profitability_snapshots', 'market_price_snapshot_id'],
        ['accounts', 'nation_id'],
        ['alliance_finance_entries', 'nation_id'],
        ['audit_result_events', 'nation_id'],
        ['audit_results', 'nation_id'],
        ['auto_withdraw_settings', 'nation_id'],
        ['blockade_relief_requests', 'blockading_nation_id'],
        ['blockade_relief_requests', 'claimed_by_nation_id'],
        ['blockade_relief_requests', 'requester_nation_id'],
        ['growth_circle_distributions', 'nation_id'],
        ['growth_circle_enrollments', 'nation_id'],
        ['inactivity_events', 'nation_id'],
        ['loans', 'nation_id'],
        ['lottery_purchases', 'nation_id'],
        ['lottery_tickets', 'nation_id'],
        ['market_transactions', 'nation_id'],
        ['member_transfers', 'from_nation_id'],
        ['member_transfers', 'to_nation_id'],
        ['member_inactivity_exceptions', 'nation_id'],
        ['milcom_assignments', 'friendly_nation_id'],
        ['milcom_incidents', 'aggressor_nation_id'],
        ['milcom_incidents', 'attacked_nation_id'],
        ['milcom_nation_capacity_locks', 'friendly_nation_id'],
        ['milcom_objectives', 'target_nation_id'],
        ['milcom_operation_nations', 'nation_id'],
        ['milcom_readiness_snapshots', 'nation_id'],
        ['nation_accounts', 'nation_id'],
        ['nation_military', 'nation_id'],
        ['nation_resources', 'nation_id'],
        ['payroll_members', 'nation_id'],
        ['spy_assignment_message_logs', 'attacker_nation_id'],
        ['spy_assignments', 'attacker_nation_id'],
        ['spy_assignments', 'defender_nation_id'],
        ['transactions', 'nation_id'],
        ['war_counter_assignments', 'friendly_nation_id'],
        ['war_counter_reimbursements', 'nation_id'],
        ['war_counters', 'aggressor_nation_id'],
        ['war_plan_assignments', 'friendly_nation_id'],
        ['war_plan_targets', 'nation_id'],
        ['milcom_assignments', 'declared_war_id'],
        ['milcom_incidents', 'war_id'],
        ['users', 'nation_id'],
        ['city_grant_requests', 'nation_id'],
        ['grant_applications', 'nation_id'],
        ['nation_sign_ins', 'nation_id'],
        ['recruited_nations', 'nation_id'],
        ['war_aid_requests', 'nation_id'],
        ['direct_deposit_logs', 'nation_id'],
        ['direct_deposit_enrollments', 'nation_id'],
        ['mmr_configs', 'nation_id'],
        ['applications', 'nation_id'],
        ['intel_reports', 'nation_id'],
        ['rebuilding_estimates', 'nation_id'],
        ['rebuilding_ineligibilities', 'nation_id'],
        ['rebuilding_requests', 'nation_id'],
        ['nation_profitability_snapshots', 'nation_id'],
        ['nation_build_recommendations', 'nation_id'],
        ['discord_assignment_responses', 'nation_id'],
        ['no_raid_list', 'alliance_id'],
        ['nation_profitability_snapshots', 'alliance_id'],
        ['nation_build_recommendations', 'alliance_id'],
        ['tax_import_rejections', 'alliance_id'],
        ['tax_import_checkpoints', 'alliance_id'],
        ['milcom_readiness_snapshots', 'alliance_id'],
        ['blockade_relief_requests', 'war_id'],
        ['war_declaration_receipts', 'war_id'],
        ['nation_profitability_snapshots', 'radiation_snapshot_id'],
        ['nation_build_recommendations', 'radiation_snapshot_id'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Hosted migration integration tests require the mysql connection.');
        }

        $this->ensureIsolatedTestDatabase('mysql');
        config(['nexus.runtime' => NexusRuntime::HostedTenant->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    public function test_fresh_hosted_migration_uses_indexed_logical_world_references(): void
    {
        $this->artisan('migrate:fresh', ['--drop-views' => true, '--force' => true])->assertSuccessful();

        foreach (array_keys(WorldModelManifest::modelsByTable()) as $worldTable) {
            $this->assertFalse(Schema::hasTable($worldTable), "Hosted migration created world table [{$worldTable}].");
        }

        foreach (['accounts', 'audit_results', 'milcom_operations', 'scheduled_task_runs'] as $tenantTable) {
            $this->assertTrue(Schema::hasTable($tenantTable), "Hosted migration omitted tenant table [{$tenantTable}].");
        }

        $worldTables = array_keys(WorldModelManifest::modelsByTable());
        $placeholders = implode(', ', array_fill(0, count($worldTables), '?'));
        $worldForeignKeyCount = DB::scalar(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND REFERENCED_TABLE_NAME IN ({$placeholders})",
            $worldTables,
        );

        $this->assertSame(0, (int) $worldForeignKeyCount, 'Hosted schema retained a physical world-table foreign key.');

        foreach (self::TENANT_WORLD_REFERENCES as [$table, $column]) {
            $this->assertTrue(Schema::hasColumn($table, $column), "Hosted schema omitted [{$table}.{$column}].");

            $indexCount = DB::scalar(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND COLUMN_NAME = ?
                        AND SEQ_IN_INDEX = 1
                    SQL,
                [$table, $column],
            );

            $this->assertGreaterThanOrEqual(1, (int) $indexCount, "Hosted world reference [{$table}.{$column}] is not indexed.");
        }
    }
}
