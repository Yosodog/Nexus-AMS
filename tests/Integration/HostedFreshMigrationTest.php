<?php

namespace Tests\Integration;

use App\Enums\NexusRuntime;
use App\Models\BootstrapRedemption;
use App\Models\TenantCallbackDelivery;
use App\Services\RuntimeCapabilities;
use App\Services\RuntimeReadinessService;
use App\Services\World\WorldModelManifest;
use Illuminate\Database\QueryException;
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

        foreach (['accounts', 'audit_results', 'bootstrap_redemptions', 'milcom_operations', 'process_heartbeats', 'scheduled_task_runs', 'tenant_callback_deliveries'] as $tenantTable) {
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

    public function test_hosted_callback_outbox_enforces_transport_and_effect_idempotency(): void
    {
        $this->artisan('migrate:fresh', ['--drop-views' => true, '--force' => true])->assertSuccessful();

        $delivery = TenantCallbackDelivery::factory()->create();

        try {
            TenantCallbackDelivery::factory()->create([
                'callback_id' => $delivery->callback_id,
            ]);
            $this->fail('The hosted outbox accepted a duplicate callback identity.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            TenantCallbackDelivery::factory()->create([
                'event_type' => $delivery->event_type,
                'subject_key' => $delivery->subject_key,
            ]);
            $this->fail('The hosted outbox accepted a duplicate callback effect.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(Schema::hasIndex('tenant_callback_deliveries', ['callback_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex(
            'tenant_callback_deliveries',
            ['event_type', 'subject_key'],
            'unique',
        ));
        $this->assertTrue(Schema::hasIndex(
            'tenant_callback_deliveries',
            ['status', 'next_attempt_at'],
        ));
        $this->assertTrue(Schema::hasIndex(
            'tenant_callback_deliveries',
            ['status', 'last_attempted_at'],
        ));
    }

    public function test_hosted_bootstrap_redemption_enforces_token_and_action_idempotency(): void
    {
        $this->artisan('migrate:fresh', ['--drop-views' => true, '--force' => true])->assertSuccessful();

        $redemption = BootstrapRedemption::factory()->create([
            'local_user_id' => null,
            'mode' => null,
            'redeemed_at' => null,
        ]);

        try {
            BootstrapRedemption::factory()->create([
                'token_hash' => $redemption->getRawOriginal('token_hash'),
                'tenant_id' => '01JZ9999999999999999999999',
                'local_user_id' => null,
                'mode' => null,
                'redeemed_at' => null,
            ]);
            $this->fail('The hosted bootstrap ledger accepted a duplicate token digest.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            BootstrapRedemption::factory()->create([
                'token_hash' => hash('sha256', 'different-bootstrap-token'),
                'tenant_id' => $redemption->tenant_id,
                'action' => $redemption->action,
                'local_user_id' => null,
                'mode' => null,
                'redeemed_at' => null,
            ]);
            $this->fail('The hosted bootstrap ledger accepted a second initial-admin action.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(Schema::hasIndex('bootstrap_redemptions', ['token_hash'], 'unique'));
        $this->assertTrue(Schema::hasIndex(
            'bootstrap_redemptions',
            ['tenant_id', 'action'],
            'unique',
        ));
        $this->assertTrue(Schema::hasIndex('bootstrap_redemptions', ['cloud_user_id']));
        $this->assertTrue(Schema::hasIndex('bootstrap_redemptions', ['local_user_id']));
    }

    public function test_hosted_readiness_accepts_the_privileged_world_view_contract(): void
    {
        $this->artisan('migrate:fresh', ['--drop-views' => true, '--force' => true])->assertSuccessful();

        foreach (array_keys(WorldModelManifest::modelsByTable()) as $worldTable) {
            DB::statement("CREATE VIEW `{$worldTable}` AS SELECT CAST(1 AS UNSIGNED) AS `id`");
        }

        config([
            'nexus.managed' => true,
            'nexus.tenant_id' => '01JZ0000000000000000000000',
            'nexus.release_id' => 'hosted-test-release',
            'nexus.runtime_contract' => 1,
            'nexus.world_view_contract' => 3,
        ]);

        $snapshot = app(RuntimeReadinessService::class)->readiness();

        $this->assertTrue($snapshot['ready']);
        $this->assertSame('compatible', $snapshot['checks']['world_views']['status']);
        $this->assertSame('current', $snapshot['checks']['tenant_schema']['status']);
    }
}
