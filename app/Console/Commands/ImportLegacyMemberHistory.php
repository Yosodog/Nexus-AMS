<?php

namespace App\Console\Commands;

use App\Services\AllianceMembershipService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Signature('data:import-legacy-member-history
    {--source-schema= : Staging schema restored from the legacy Nexus database}
    {--execute : Commit the import; without this option the command is read-only}
    {--allow-conflicts : Keep destination rows when the same identity has different data}
    {--minimum-free-gb=8 : Minimum free disk space required before execution}
    {--database-socket= : Local MySQL socket for an isolated administrative run}
    {--database-user= : Database user for the isolated administrative run}')]
#[Description('Safely import current-member history and global market/world history from a legacy schema')]
class ImportLegacyMemberHistory extends Command
{
    private const REQUIRED_TABLES = [
        'nations',
        'users',
        'taxes',
        'nation_sign_ins',
        'intel_reports',
        'wars',
        'war_attacks',
        'market_trades',
        'trade_prices',
        'market_price_snapshots',
        'market_price_snapshot_items',
        'radiation_snapshots',
    ];

    private Connection $database;

    private string $destinationSchema;

    private string $sourceSchema;

    /** @var array<string, array<int, string>> */
    private array $columnCache = [];

    public function handle(AllianceMembershipService $membershipService): int
    {
        $this->configureAdministrativeConnection();
        $this->database = DB::connection();
        $this->destinationSchema = (string) $this->database->getDatabaseName();
        $this->sourceSchema = (string) $this->option('source-schema');

        try {
            $this->validateEnvironment();
            $this->validateTables();
            $this->createScopeTables($membershipService);

            $reports = $this->buildReports();
            $this->renderSummary($membershipService, $reports);

            if ($this->hasConflicts($reports) && ! $this->option('allow-conflicts')) {
                throw new RuntimeException('Conflicting rows were found. No live data has been changed.');
            }

            if ($this->hasConflicts($reports)) {
                $this->warn('Conflicting identities will keep the destination values; they will not be updated.');
            }

            if (! $this->option('execute')) {
                $this->newLine();
                $this->warn('Dry run only. Re-run with --execute after reviewing this report.');

                return self::SUCCESS;
            }

            $this->assertFreeDiskSpace();
            $this->database->transaction(function (): void {
                $this->executeImport();
            }, 1);

            Cache::forget('tax_summary_stats');
            Cache::forget('tax_resource_chart_data');
            Cache::forget('tax_daily_totals');

            $this->newLine();
            $this->info('Legacy member history import committed successfully.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->dropTemporaryTables();
        }
    }

    private function configureAdministrativeConnection(): void
    {
        $socket = (string) $this->option('database-socket');
        $user = (string) $this->option('database-user');

        if ($socket === '' && $user === '') {
            return;
        }

        if ($socket === '' || $user === '') {
            throw new RuntimeException('--database-socket and --database-user must be supplied together.');
        }

        if (! file_exists($socket) || filetype($socket) !== 'socket') {
            throw new RuntimeException("MySQL socket [{$socket}] does not exist or is not a socket.");
        }

        config([
            'database.connections.mysql.unix_socket' => $socket,
            'database.connections.mysql.host' => 'localhost',
            'database.connections.mysql.username' => $user,
            'database.connections.mysql.password' => '',
        ]);
        DB::purge('mysql');
    }

    private function validateEnvironment(): void
    {
        if ($this->database->getDriverName() !== 'mysql') {
            throw new RuntimeException('This production import command requires MySQL.');
        }

        foreach ([$this->sourceSchema, $this->destinationSchema] as $schema) {
            if ($schema === '' || preg_match('/^[A-Za-z0-9_]+$/', $schema) !== 1) {
                throw new RuntimeException('Database schema names may contain only letters, digits, and underscores.');
            }
        }

        if ($this->sourceSchema === $this->destinationSchema) {
            throw new RuntimeException('The legacy source and live destination schemas must be different.');
        }

        $sourceExists = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
            [$this->sourceSchema],
        );

        if ($sourceExists !== 1) {
            throw new RuntimeException("Legacy source schema [{$this->sourceSchema}] does not exist.");
        }

        $minimumFreeGigabytes = filter_var($this->option('minimum-free-gb'), FILTER_VALIDATE_FLOAT);

        if ($minimumFreeGigabytes === false || $minimumFreeGigabytes < 1) {
            throw new RuntimeException('--minimum-free-gb must be a number of at least 1.');
        }

        if ($this->option('execute')) {
            $this->assertFreeDiskSpace();
        }
    }

    private function validateTables(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            $sourceColumns = $this->columns($this->sourceSchema, $table, false);
            $destinationColumns = $this->columns($this->destinationSchema, $table, false);

            if ($sourceColumns === [] || $destinationColumns === []) {
                throw new RuntimeException("Required table [{$table}] is missing from the source or destination schema.");
            }

            $allowedDestinationOnlyColumns = $table === 'war_attacks' ? ['is_legacy_history'] : [];

            if ($sourceColumns !== array_values(array_diff($destinationColumns, $allowedDestinationOnlyColumns))) {
                throw new RuntimeException("Column drift detected for [{$table}]. No import is safe until the schemas match.");
            }
        }
    }

    private function assertFreeDiskSpace(): void
    {
        $freeBytes = disk_free_space('/var/lib/mysql');

        if ($freeBytes === false) {
            throw new RuntimeException('Unable to determine free space for /var/lib/mysql.');
        }

        $minimumBytes = (float) $this->option('minimum-free-gb') * 1024 * 1024 * 1024;

        if ($freeBytes < $minimumBytes) {
            throw new RuntimeException(sprintf(
                'Only %.2f GiB is free; %.2f GiB is required. No import was attempted.',
                $freeBytes / 1024 / 1024 / 1024,
                $minimumBytes / 1024 / 1024 / 1024,
            ));
        }
    }

    private function createScopeTables(AllianceMembershipService $membershipService): void
    {
        $allianceIds = $membershipService->getAllianceIds()->map(fn (mixed $id): int => (int) $id)->all();

        if ($allianceIds === []) {
            throw new RuntimeException('The current alliance umbrella resolved to no alliance IDs.');
        }

        $this->database->statement('CREATE TEMPORARY TABLE legacy_import_alliances (alliance_id BIGINT UNSIGNED PRIMARY KEY)');
        $this->database->table('legacy_import_alliances')->insert(
            array_map(fn (int $id): array => ['alliance_id' => $id], $allianceIds),
        );

        $this->database->statement('CREATE TEMPORARY TABLE legacy_import_current_nations (nation_id BIGINT UNSIGNED PRIMARY KEY)');
        $this->database->statement(sprintf(
            'INSERT INTO legacy_import_current_nations (nation_id)
             SELECT n.id FROM %s.nations n
             JOIN legacy_import_alliances a ON a.alliance_id = n.alliance_id
             WHERE n.deleted_at IS NULL',
            $this->identifier($this->destinationSchema),
        ));

        if ((int) $this->database->scalar('SELECT COUNT(*) FROM legacy_import_current_nations') === 0) {
            throw new RuntimeException('No current member nations were found in the live database.');
        }

        $this->database->statement('CREATE TEMPORARY TABLE legacy_import_war_ids (id BIGINT UNSIGNED PRIMARY KEY)');
        $this->database->statement(sprintf(
            'INSERT INTO legacy_import_war_ids (id)
             SELECT DISTINCT w.id FROM %s.wars w
             JOIN legacy_import_current_nations member ON member.nation_id IN (w.att_id, w.def_id)',
            $this->identifier($this->sourceSchema),
        ));

        $this->database->statement('CREATE TEMPORARY TABLE legacy_import_attack_ids (id BIGINT UNSIGNED PRIMARY KEY)');
        $this->database->statement(sprintf(
            'INSERT INTO legacy_import_attack_ids (id)
             SELECT DISTINCT wa.id FROM %s.war_attacks wa
             LEFT JOIN legacy_import_war_ids w ON w.id = wa.war_id
             LEFT JOIN legacy_import_current_nations member ON member.nation_id IN (wa.att_id, wa.def_id)
             WHERE w.id IS NOT NULL OR member.nation_id IS NOT NULL',
            $this->identifier($this->sourceSchema),
        ));

        $this->database->statement('CREATE TEMPORARY TABLE legacy_import_dependency_nations (nation_id BIGINT UNSIGNED PRIMARY KEY)');
        $this->database->statement(sprintf(
            'INSERT INTO legacy_import_dependency_nations (nation_id)
             SELECT DISTINCT participants.nation_id
             FROM (
                 SELECT CASE participant.side WHEN 1 THEN w.att_id ELSE w.def_id END AS nation_id
                 FROM %1$s.wars w
                 JOIN legacy_import_war_ids selected ON selected.id = w.id
                 CROSS JOIN (SELECT 1 AS side UNION ALL SELECT 2) participant
                 UNION
                 SELECT CASE participant.side WHEN 1 THEN wa.att_id ELSE wa.def_id END AS nation_id
                 FROM %1$s.war_attacks wa
                 JOIN legacy_import_attack_ids selected ON selected.id = wa.id
                 CROSS JOIN (SELECT 1 AS side UNION ALL SELECT 2) participant
             ) participants
             LEFT JOIN %2$s.nations existing ON existing.id = participants.nation_id
             WHERE existing.id IS NULL',
            $this->identifier($this->sourceSchema),
            $this->identifier($this->destinationSchema),
        ));

    }

    /**
     * @return array<string, array{selected: int, insertable: int, existing: int, conflicts: int}>
     */
    private function buildReports(): array
    {
        return [
            'historical_nations' => $this->dependencyNationReport(true),
            'historical_nation_placeholders' => $this->dependencyNationReport(false),
            'wars' => $this->externalIdReport('wars', 'JOIN legacy_import_war_ids selected ON selected.id = s.id'),
            'war_attacks' => $this->externalIdReport('war_attacks', 'JOIN legacy_import_attack_ids selected ON selected.id = s.id'),
            'taxes' => $this->externalIdReport('taxes', $this->taxScope('s')),
            'nation_sign_ins' => $this->exactPayloadReport(
                'nation_sign_ins',
                'JOIN legacy_import_current_nations member ON member.nation_id = s.nation_id',
                ['id', 'sign_in_day'],
                ['nation_id', 'created_at'],
            ),
            'intel_reports' => $this->intelReport(),
            'market_trades' => $this->externalIdReport('market_trades', $this->marketTradeScope('s')),
            'trade_prices' => $this->exactPayloadReport('trade_prices', '', ['id'], ['created_at']),
            'radiation_snapshots' => $this->naturalKeyReport(
                'radiation_snapshots',
                ['snapshot_at'],
                ['id', 'created_at', 'updated_at'],
            ),
            'market_price_snapshots' => $this->naturalKeyReport(
                'market_price_snapshots',
                ['basis', 'window_started_at', 'window_ended_at', 'calculated_at'],
                ['id', 'created_at', 'updated_at'],
            ),
        ];
    }

    /** @return array{selected: int, insertable: int, existing: int, conflicts: int} */
    private function dependencyNationReport(bool $sourceAvailable): array
    {
        $comparison = $sourceAvailable ? 'IS NOT NULL' : 'IS NULL';
        $count = (int) $this->database->scalar(sprintf(
            'SELECT COUNT(*) FROM legacy_import_dependency_nations dependency
             LEFT JOIN %s source_nation ON source_nation.id = dependency.nation_id
             WHERE source_nation.id %s',
            $this->qualified($this->sourceSchema, 'nations'),
            $comparison,
        ));

        return ['selected' => $count, 'insertable' => $count, 'existing' => 0, 'conflicts' => 0];
    }

    /** @return array{selected: int, insertable: int, existing: int, conflicts: int} */
    private function externalIdReport(string $table, string $scope = ''): array
    {
        $columns = array_values(array_diff($this->columns($this->sourceSchema, $table), ['created_at', 'updated_at']));
        $differences = $this->differencePredicate($columns, 's', 'd');
        $source = $this->qualified($this->sourceSchema, $table);
        $destination = $this->qualified($this->destinationSchema, $table);

        $selected = (int) $this->database->scalar("SELECT COUNT(*) FROM {$source} s {$scope}");
        $insertable = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM {$source} s {$scope} LEFT JOIN {$destination} d ON d.id = s.id WHERE d.id IS NULL",
        );
        $conflicts = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM {$source} s {$scope} JOIN {$destination} d ON d.id = s.id WHERE {$differences}",
        );

        return [
            'selected' => $selected,
            'insertable' => $insertable,
            'existing' => $selected - $insertable - $conflicts,
            'conflicts' => $conflicts,
        ];
    }

    /** @return array{selected: int, insertable: int, existing: int, conflicts: int} */
    private function exactPayloadReport(string $table, string $scope, array $excludedColumns, array $keyColumns): array
    {
        $columns = array_values(array_diff($this->columns($this->sourceSchema, $table), $excludedColumns));
        $columnList = $this->columnList($columns, 's');
        $matches = $this->equalityPredicate($keyColumns, 'candidate', 'd')
            .' AND '.$this->equalityPredicate($columns, 'candidate', 'd');
        $source = $this->qualified($this->sourceSchema, $table);
        $destination = $this->qualified($this->destinationSchema, $table);
        $candidateSql = "SELECT DISTINCT {$columnList} FROM {$source} s {$scope}";

        $selected = (int) $this->database->scalar("SELECT COUNT(*) FROM ({$candidateSql}) candidate");
        $insertable = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM ({$candidateSql}) candidate WHERE NOT EXISTS (SELECT 1 FROM {$destination} d WHERE {$matches})",
        );

        return [
            'selected' => $selected,
            'insertable' => $insertable,
            'existing' => $selected - $insertable,
            'conflicts' => 0,
        ];
    }

    /** @return array{selected: int, insertable: int, existing: int, conflicts: int} */
    private function intelReport(): array
    {
        $source = $this->qualified($this->sourceSchema, 'intel_reports');
        $destination = $this->qualified($this->destinationSchema, 'intel_reports');
        $duplicateHashes = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM (SELECT hash FROM {$source} GROUP BY hash HAVING COUNT(*) > 1) duplicates",
        );

        if ($duplicateHashes > 0) {
            throw new RuntimeException("The legacy intel table contains {$duplicateHashes} duplicate hashes; import is ambiguous.");
        }

        $selected = (int) $this->database->scalar("SELECT COUNT(*) FROM {$source}");
        $insertable = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM {$source} s WHERE NOT EXISTS (SELECT 1 FROM {$destination} d WHERE d.hash = s.hash)",
        );

        return [
            'selected' => $selected,
            'insertable' => $insertable,
            'existing' => $selected - $insertable,
            'conflicts' => 0,
        ];
    }

    /** @return array{selected: int, insertable: int, existing: int, conflicts: int} */
    private function naturalKeyReport(string $table, array $keyColumns, array $excludedColumns): array
    {
        $columns = array_values(array_diff($this->columns($this->sourceSchema, $table), $excludedColumns));
        $source = $this->qualified($this->sourceSchema, $table);
        $destination = $this->qualified($this->destinationSchema, $table);
        $keyMatch = $this->equalityPredicate($keyColumns, 's', 'd');
        $differences = $this->differencePredicate($columns, 's', 'd');
        $selected = (int) $this->database->scalar("SELECT COUNT(*) FROM {$source}");
        $insertable = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM {$source} s WHERE NOT EXISTS (SELECT 1 FROM {$destination} d WHERE {$keyMatch})",
        );
        $conflicts = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM {$source} s JOIN {$destination} d ON {$keyMatch} WHERE {$differences}",
        );

        return [
            'selected' => $selected,
            'insertable' => $insertable,
            'existing' => $selected - $insertable - $conflicts,
            'conflicts' => $conflicts,
        ];
    }

    /** @param array<string, array{selected: int, insertable: int, existing: int, conflicts: int}> $reports */
    private function renderSummary(AllianceMembershipService $membershipService, array $reports): void
    {
        $this->info($this->option('execute') ? 'EXECUTION PREFLIGHT' : 'DRY RUN');
        $this->line('Source schema: '.$this->sourceSchema);
        $this->line('Destination schema: '.$this->destinationSchema);
        $this->line('Alliance umbrella: '.$membershipService->getAllianceIds()->implode(', '));
        $this->line('Current member nations: '.$this->database->scalar('SELECT COUNT(*) FROM legacy_import_current_nations'));
        $this->line('Free disk: '.sprintf('%.2f GiB', (float) disk_free_space('/var/lib/mysql') / 1024 / 1024 / 1024));
        $this->newLine();
        $this->table(
            ['Dataset', 'Selected', 'Insertable', 'Already identical', 'Conflicts'],
            collect($reports)->map(fn (array $report, string $dataset): array => [
                $dataset,
                number_format($report['selected']),
                number_format($report['insertable']),
                number_format($report['existing']),
                number_format($report['conflicts']),
            ])->values()->all(),
        );
    }

    /** @param array<string, array{selected: int, insertable: int, existing: int, conflicts: int}> $reports */
    private function hasConflicts(array $reports): bool
    {
        return collect($reports)->contains(fn (array $report): bool => $report['conflicts'] > 0);
    }

    private function executeImport(): void
    {
        $this->insertDependencyNations();
        $this->insertPlaceholderDependencyNations();
        $this->insertExternalIdRows('wars', 'JOIN legacy_import_war_ids selected ON selected.id = s.id');
        $this->insertExternalIdRows('war_attacks', 'JOIN legacy_import_attack_ids selected ON selected.id = s.id');
        $this->database->affectingStatement(sprintf(
            'UPDATE %s attacks
             JOIN legacy_import_attack_ids selected ON selected.id = attacks.id
             SET attacks.is_legacy_history = 1
             WHERE attacks.is_legacy_history = 0',
            $this->qualified($this->destinationSchema, 'war_attacks'),
        ));
        $this->insertExternalIdRows('taxes', $this->taxScope('s'));
        $this->insertExactPayloadRows(
            'nation_sign_ins',
            'JOIN legacy_import_current_nations member ON member.nation_id = s.nation_id',
            ['id', 'sign_in_day'],
            ['nation_id', 'created_at'],
        );
        $this->insertIntelReports();
        $this->insertExternalIdRows('market_trades', $this->marketTradeScope('s'));
        $this->insertExactPayloadRows('trade_prices', '', ['id'], ['created_at']);
        $this->insertNaturalKeyRows('radiation_snapshots', ['snapshot_at'], ['id']);
        $this->insertMarketSnapshots();
    }

    private function insertDependencyNations(): void
    {
        $columns = $this->columns($this->sourceSchema, 'nations');
        $selects = array_map(
            fn (string $column): string => $column === 'deleted_at'
                ? 'COALESCE(s.deleted_at, CURRENT_TIMESTAMP) AS '.$this->identifier($column)
                : 's.'.$this->identifier($column),
            $columns,
        );

        $this->database->affectingStatement(sprintf(
            'INSERT INTO %s (%s)
             SELECT %s FROM %s s
             JOIN legacy_import_dependency_nations dependency ON dependency.nation_id = s.id',
            $this->qualified($this->destinationSchema, 'nations'),
            $this->columnList($columns),
            implode(', ', $selects),
            $this->qualified($this->sourceSchema, 'nations'),
        ));
    }

    private function insertPlaceholderDependencyNations(): void
    {
        $this->database->affectingStatement(sprintf(
            "INSERT INTO %1\$s (
                id, alliance_id, alliance_position, alliance_position_id, nation_name, leader_name,
                continent, war_policy, war_policy_turns, domestic_policy, domestic_policy_turns,
                color, num_cities, score, population, vacation_mode_turns, beige_turns,
                espionage_available, turns_since_last_city, turns_since_last_project, projects,
                project_bits, wars_won, wars_lost, alliance_seniority, gross_national_income,
                gross_domestic_product, vip, commendations, denouncements, offensive_wars_count,
                defensive_wars_count, money_looted, total_infrastructure_destroyed,
                total_infrastructure_lost, created_at, updated_at, deleted_at
             )
             SELECT
                dependency.nation_id, NULL, 'NOALLIANCE', 0,
                CONCAT('Historical Nation ', dependency.nation_id),
                CONCAT('Historical Leader ', dependency.nation_id),
                'NA', 'ATTRITION', 0, 'MANIFEST_DESTINY', 0,
                'gray', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             FROM legacy_import_dependency_nations dependency
             LEFT JOIN %2\$s source_nation ON source_nation.id = dependency.nation_id
             WHERE source_nation.id IS NULL",
            $this->qualified($this->destinationSchema, 'nations'),
            $this->qualified($this->sourceSchema, 'nations'),
        ));
    }

    private function insertExternalIdRows(string $table, string $scope = ''): void
    {
        $columns = $this->columns($this->sourceSchema, $table);
        $this->database->affectingStatement(sprintf(
            'INSERT INTO %s (%s)
             SELECT %s FROM %s s %s
             LEFT JOIN %s d ON d.id = s.id
             WHERE d.id IS NULL',
            $this->qualified($this->destinationSchema, $table),
            $this->columnList($columns),
            $this->columnList($columns, 's'),
            $this->qualified($this->sourceSchema, $table),
            $scope,
            $this->qualified($this->destinationSchema, $table),
        ));
    }

    private function insertExactPayloadRows(string $table, string $scope, array $excludedColumns, array $keyColumns): void
    {
        $columns = array_values(array_diff($this->columns($this->sourceSchema, $table), $excludedColumns));
        $source = $this->qualified($this->sourceSchema, $table);
        $destination = $this->qualified($this->destinationSchema, $table);
        $candidateSql = 'SELECT DISTINCT '.$this->columnList($columns, 's')." FROM {$source} s {$scope}";
        $matches = $this->equalityPredicate($keyColumns, 'candidate', 'd')
            .' AND '.$this->equalityPredicate($columns, 'candidate', 'd');

        $this->database->affectingStatement(sprintf(
            'INSERT INTO %s (%s)
             SELECT %s FROM (%s) candidate
             WHERE NOT EXISTS (SELECT 1 FROM %s d WHERE %s)',
            $destination,
            $this->columnList($columns),
            $this->columnList($columns, 'candidate'),
            $candidateSql,
            $destination,
            $matches,
        ));
    }

    private function insertIntelReports(): void
    {
        $columns = array_values(array_diff($this->columns($this->sourceSchema, 'intel_reports'), ['id']));
        $selects = array_map(
            fn (string $column): string => $column === 'user_id'
                ? 'destination_user.id AS user_id'
                : 's.'.$this->identifier($column),
            $columns,
        );

        $this->database->affectingStatement(sprintf(
            'INSERT INTO %1$s (%2$s)
             SELECT %3$s FROM %4$s s
             LEFT JOIN %5$s source_user ON source_user.id = s.user_id
             LEFT JOIN %6$s destination_user ON destination_user.nation_id = source_user.nation_id
             WHERE NOT EXISTS (SELECT 1 FROM %1$s existing WHERE existing.hash = s.hash)',
            $this->qualified($this->destinationSchema, 'intel_reports'),
            $this->columnList($columns),
            implode(', ', $selects),
            $this->qualified($this->sourceSchema, 'intel_reports'),
            $this->qualified($this->sourceSchema, 'users'),
            $this->qualified($this->destinationSchema, 'users'),
        ));
    }

    private function insertNaturalKeyRows(string $table, array $keyColumns, array $excludedColumns): void
    {
        $columns = array_values(array_diff($this->columns($this->sourceSchema, $table), $excludedColumns));
        $source = $this->qualified($this->sourceSchema, $table);
        $destination = $this->qualified($this->destinationSchema, $table);
        $keyMatch = $this->equalityPredicate($keyColumns, 's', 'd');

        $this->database->affectingStatement(sprintf(
            'INSERT INTO %s (%s)
             SELECT %s FROM %s s
             WHERE NOT EXISTS (SELECT 1 FROM %s d WHERE %s)',
            $destination,
            $this->columnList($columns),
            $this->columnList($columns, 's'),
            $source,
            $destination,
            $keyMatch,
        ));
    }

    private function insertMarketSnapshots(): void
    {
        $keys = ['basis', 'window_started_at', 'window_ended_at', 'calculated_at'];
        $this->insertNaturalKeyRows('market_price_snapshots', $keys, ['id']);

        $this->database->statement('CREATE TEMPORARY TABLE legacy_import_market_snapshot_map (
            source_id BIGINT UNSIGNED PRIMARY KEY,
            destination_id BIGINT UNSIGNED NOT NULL
        )');
        $this->database->statement(sprintf(
            'INSERT INTO legacy_import_market_snapshot_map (source_id, destination_id)
             SELECT source.id, destination.id
             FROM %1$s source
             JOIN %2$s destination ON %3$s',
            $this->qualified($this->sourceSchema, 'market_price_snapshots'),
            $this->qualified($this->destinationSchema, 'market_price_snapshots'),
            $this->equalityPredicate($keys, 'source', 'destination'),
        ));

        $columns = array_values(array_diff(
            $this->columns($this->sourceSchema, 'market_price_snapshot_items'),
            ['id', 'market_price_snapshot_id'],
        ));
        $destination = $this->qualified($this->destinationSchema, 'market_price_snapshot_items');

        $this->database->affectingStatement(sprintf(
            'INSERT INTO %1$s (market_price_snapshot_id, %2$s)
             SELECT snapshot_map.destination_id, %3$s
             FROM %4$s s
             JOIN legacy_import_market_snapshot_map snapshot_map ON snapshot_map.source_id = s.market_price_snapshot_id
             WHERE NOT EXISTS (
                 SELECT 1 FROM %1$s d
                 WHERE d.market_price_snapshot_id = snapshot_map.destination_id AND d.resource = s.resource
             )',
            $destination,
            $this->columnList($columns),
            $this->columnList($columns, 's'),
            $this->qualified($this->sourceSchema, 'market_price_snapshot_items'),
        ));
    }

    private function taxScope(string $alias): string
    {
        return "JOIN legacy_import_current_nations member ON member.nation_id = {$alias}.sender_id
                JOIN legacy_import_alliances receiver ON receiver.alliance_id = {$alias}.receiver_id AND {$alias}.receiver_type = 2";
    }

    private function marketTradeScope(string $alias): string
    {
        return "JOIN (SELECT 1) retention_window ON {$alias}.accepted_at >= UTC_TIMESTAMP() - INTERVAL 8 DAY";
    }

    /** @return array<int, string> */
    private function columns(string $schema, string $table, bool $includeGenerated = true): array
    {
        $cacheKey = $schema.'.'.$table.'.'.($includeGenerated ? 'all' : 'all-including-generated');

        if (! isset($this->columnCache[$cacheKey])) {
            $sql = 'SELECT column_name AS column_name_value FROM information_schema.columns
                    WHERE table_schema = ? AND table_name = ?';
            $bindings = [$schema, $table];

            if ($includeGenerated) {
                $sql .= " AND generation_expression = ''";
            }

            $sql .= ' ORDER BY ordinal_position';
            $this->columnCache[$cacheKey] = array_map(
                fn (object $row): string => (string) $row->column_name_value,
                $this->database->select($sql, $bindings),
            );
        }

        return $this->columnCache[$cacheKey];
    }

    /** @param array<int, string> $columns */
    private function columnList(array $columns, ?string $alias = null): string
    {
        $prefix = $alias === null ? '' : $this->identifier($alias).'.';

        return implode(', ', array_map(fn (string $column): string => $prefix.$this->identifier($column), $columns));
    }

    /** @param array<int, string> $columns */
    private function equalityPredicate(array $columns, string $leftAlias, string $rightAlias): string
    {
        return implode(' AND ', array_map(
            fn (string $column): string => sprintf(
                '%s.%s <=> %s.%s',
                $this->identifier($leftAlias),
                $this->identifier($column),
                $this->identifier($rightAlias),
                $this->identifier($column),
            ),
            $columns,
        ));
    }

    /** @param array<int, string> $columns */
    private function differencePredicate(array $columns, string $leftAlias, string $rightAlias): string
    {
        return 'NOT ('.$this->equalityPredicate($columns, $leftAlias, $rightAlias).')';
    }

    private function qualified(string $schema, string $table): string
    {
        return $this->identifier($schema).'.'.$this->identifier($table);
    }

    private function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException("Unsafe SQL identifier [{$identifier}].");
        }

        return '`'.$identifier.'`';
    }

    private function dropTemporaryTables(): void
    {
        if (! isset($this->database)) {
            return;
        }

        foreach ([
            'legacy_import_market_snapshot_map',
            'legacy_import_dependency_nations',
            'legacy_import_attack_ids',
            'legacy_import_war_ids',
            'legacy_import_current_nations',
            'legacy_import_alliances',
        ] as $table) {
            try {
                $this->database->statement('DROP TEMPORARY TABLE IF EXISTS '.$this->identifier($table));
            } catch (Throwable) {
                // The connection may already be unavailable while handling the original failure.
            }
        }
    }
}
