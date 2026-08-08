<?php

namespace Tests\Integration;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use App\Services\World\WorldModelManifest;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeUpgradeMigrationTest extends TestCase
{
    private const UPGRADE_CUTOVER = '2026_08_01_201215_create_tax_import_rejections_table';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Runtime upgrade migration tests require the mysql connection.');
        }

        $this->ensureIsolatedTestDatabase('mysql');
    }

    public function test_standalone_upgrade_resumes_and_preserves_world_foreign_keys_and_data(): void
    {
        $this->configureRuntime(NexusRuntime::Standalone);
        [$pendingMigrations, $migrationCount] = $this->createUpgradeBaseline();

        DB::table('alliances')->insert([
            'id' => 1001,
            'name' => 'Upgrade Alliance',
            'acronym' => 'UP',
            'score' => 1000,
            'color' => 'blue',
            'average_score' => 1000,
            'rank' => 1,
        ]);
        $this->insertLogicalAllianceReference();
        $this->assertJoinedAllianceReference();

        $this->resumeInterruptedUpgrade($pendingMigrations, $migrationCount);

        $this->assertJoinedAllianceReference();
        $this->assertSame(53, $this->worldForeignKeyCount());
    }

    public function test_hosted_upgrade_resumes_without_mutating_existing_world_views(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);
        [$pendingMigrations, $migrationCount] = $this->createUpgradeBaseline();
        $this->installHostedWorldViews();
        $this->insertLogicalAllianceReference();
        $this->assertHostedWorldViewsReadable();
        $this->assertJoinedAllianceReference();

        $this->resumeInterruptedUpgrade($pendingMigrations, $migrationCount);

        $this->assertHostedWorldViewsReadable();
        $this->assertJoinedAllianceReference();
        $this->assertSame(0, $this->worldForeignKeyCount());
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private function createUpgradeBaseline(): array
    {
        $this->artisan('db:wipe', ['--drop-views' => true, '--force' => true])->assertSuccessful();
        $this->artisan('migrate:install')->assertSuccessful();

        $migrator = app(Migrator::class);
        $migrationFiles = $migrator->getMigrationFiles([database_path('migrations')]);
        $baselineMigrations = array_filter(
            $migrationFiles,
            static fn (string $path, string $name): bool => strcmp($name, self::UPGRADE_CUTOVER) < 0,
            ARRAY_FILTER_USE_BOTH,
        );
        $pendingMigrations = array_values(array_diff_key($migrationFiles, $baselineMigrations));

        $this->assertNotEmpty($baselineMigrations);
        $this->assertNotEmpty($pendingMigrations);

        $migrator->run(array_values($baselineMigrations));

        $this->assertCount(count($baselineMigrations), $migrator->getRepository()->getRan());

        return [$pendingMigrations, count($migrationFiles)];
    }

    /**
     * @param  list<string>  $pendingMigrations
     */
    private function resumeInterruptedUpgrade(array $pendingMigrations, int $migrationCount): void
    {
        $firstAttempt = array_slice($pendingMigrations, 0, max(1, intdiv(count($pendingMigrations), 2)));
        app(Migrator::class)->run($firstAttempt);

        $this->assertLessThan($migrationCount, count(app(Migrator::class)->getRepository()->getRan()));

        $this->app->forgetInstance('migrator');
        $this->app->forgetInstance(Migrator::class);
        $resumedMigrator = app(Migrator::class);
        $resumedMigrator->run([database_path('migrations')]);

        $this->assertCount($migrationCount, $resumedMigrator->getRepository()->getRan());
        $this->assertSame([], $resumedMigrator->run([database_path('migrations')]));
    }

    private function configureRuntime(NexusRuntime $runtime): void
    {
        config(['nexus.runtime' => $runtime->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    private function installHostedWorldViews(): void
    {
        foreach (array_keys(WorldModelManifest::modelsByTable()) as $worldTable) {
            DB::statement("CREATE VIEW `{$worldTable}` AS SELECT CAST(1001 AS UNSIGNED) AS `id`");
        }
    }

    private function assertHostedWorldViewsReadable(): void
    {
        foreach (array_keys(WorldModelManifest::modelsByTable()) as $worldTable) {
            $tableType = DB::scalar(
                'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$worldTable],
            );

            $this->assertSame('VIEW', $tableType, "Hosted upgrade replaced world view [{$worldTable}].");
            $this->assertSame(1001, (int) DB::table($worldTable)->value('id'));
        }
    }

    private function insertLogicalAllianceReference(): void
    {
        DB::table('offshores')->insert([
            'id' => 2001,
            'name' => 'Upgrade reference',
            'alliance_id' => 1001,
        ]);
    }

    private function assertJoinedAllianceReference(): void
    {
        $allianceId = DB::table('offshores')
            ->join('alliances', 'alliances.id', '=', 'offshores.alliance_id')
            ->where('offshores.id', 2001)
            ->value('alliances.id');

        $this->assertSame(1001, (int) $allianceId);
    }

    private function worldForeignKeyCount(): int
    {
        $worldTables = array_keys(WorldModelManifest::modelsByTable());
        $placeholders = implode(', ', array_fill(0, count($worldTables), '?'));

        return (int) DB::scalar(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND REFERENCED_TABLE_NAME IN ({$placeholders})",
            $worldTables,
        );
    }
}
