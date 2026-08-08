<?php

namespace Tests\Integration;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use App\Support\Database\WorldReference;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorldReferenceTest extends TestCase
{
    private string $parentTable = '';

    private string $view = '';

    private string $childTable = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('World reference integration tests require the mysql connection.');
        }

        $this->ensureIsolatedTestDatabase('mysql');

        $suffix = bin2hex(random_bytes(5));
        $this->parentTable = "world_reference_parent_{$suffix}";
        $this->view = "world_reference_view_{$suffix}";
        $this->childTable = "world_reference_child_{$suffix}";

        Schema::create($this->parentTable, function (Blueprint $table): void {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql') {
            if ($this->childTable !== '') {
                Schema::dropIfExists($this->childTable);
            }

            if ($this->view !== '') {
                DB::statement("DROP VIEW IF EXISTS `{$this->view}`");
            }

            if ($this->parentTable !== '') {
                Schema::dropIfExists($this->parentTable);
            }
        }

        parent::tearDown();
    }

    public function test_standalone_reference_preserves_foreign_key_actions(): void
    {
        $this->configureRuntime(NexusRuntime::Standalone);
        $this->createChildReference($this->parentTable);

        $foreignKey = $this->foreignKeyDefinition();

        $this->assertNotNull($foreignKey);
        $this->assertSame($this->parentTable, $foreignKey->referenced_table_name);
        $this->assertSame('CASCADE', $foreignKey->update_rule);
        $this->assertSame('RESTRICT', $foreignKey->delete_rule);
        $this->assertColumnAndIndexShape();

        DB::table($this->parentTable)->insert(['id' => 100]);
        DB::table($this->childTable)->insert(['id' => 1, 'nation_id' => 100]);
        DB::table($this->parentTable)->where('id', 100)->update(['id' => 101]);

        $this->assertSame(101, DB::table($this->childTable)->value('nation_id'));

        try {
            DB::table($this->parentTable)->where('id', 101)->delete();
            $this->fail('Standalone foreign key allowed deletion of a referenced world identity.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->getCode());
        }
    }

    public function test_hosted_reference_targets_a_view_with_only_an_indexed_logical_id(): void
    {
        DB::statement("CREATE VIEW `{$this->view}` AS SELECT `id` FROM `{$this->parentTable}`");

        $this->configureRuntime(NexusRuntime::HostedTenant);
        $this->createChildReference($this->view, withStandaloneActions: false);

        $this->assertNull($this->foreignKeyDefinition());
        $this->assertColumnAndIndexShape();

        DB::table($this->parentTable)->insert(['id' => 200]);
        DB::table($this->childTable)->insert([
            ['id' => 1, 'nation_id' => 200],
            ['id' => 2, 'nation_id' => 999],
            ['id' => 3, 'nation_id' => null],
        ]);

        $joinedIds = DB::table($this->childTable)
            ->join($this->view, "{$this->view}.id", '=', "{$this->childTable}.nation_id")
            ->pluck("{$this->childTable}.id")
            ->all();

        $this->assertSame([1], $joinedIds);
        $this->assertSame(3, DB::table($this->childTable)->count());
    }

    public function test_unnamed_index_modifier_keeps_its_conventional_name_in_standalone(): void
    {
        $this->configureRuntime(NexusRuntime::Standalone);

        Schema::create($this->childTable, function (Blueprint $table): void {
            $table->id();
            WorldReference::to($table, $this->parentTable, 'nation_id')->index();
        });

        $this->assertContains("{$this->childTable}_nation_id_index", $this->indexNames());
    }

    public function test_unnamed_unique_modifier_is_retained_in_hosted(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);

        Schema::create($this->childTable, function (Blueprint $table): void {
            $table->id();
            WorldReference::to($table, $this->view, 'nation_id')->unique();
        });

        $this->assertContains("{$this->childTable}_nation_id_unique", $this->indexNames());
    }

    public function test_primary_world_reference_is_indexed_without_a_redundant_hosted_index(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);

        Schema::create($this->childTable, function (Blueprint $table): void {
            WorldReference::to(
                table: $table,
                referencedTable: $this->view,
                column: 'nation_id',
                indexInHosted: false,
            )->primary();
        });

        $this->assertSame(['PRIMARY'], $this->indexNames());
    }

    public function test_logical_reference_adds_an_index_only_in_hosted(): void
    {
        $this->configureRuntime(NexusRuntime::HostedTenant);

        Schema::create($this->childTable, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            WorldReference::indexInHosted($table, 'alliance_id');
        });

        $this->assertSame(
            ['PRIMARY', "{$this->childTable}_alliance_id_index"],
            $this->indexNames(),
        );
    }

    public function test_logical_reference_preserves_standalone_index_shape(): void
    {
        $this->configureRuntime(NexusRuntime::Standalone);

        Schema::create($this->childTable, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            WorldReference::indexInHosted($table, 'alliance_id');
        });

        $this->assertSame(['PRIMARY'], $this->indexNames());
    }

    private function configureRuntime(NexusRuntime $runtime): void
    {
        config(['nexus.runtime' => $runtime->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    private function createChildReference(string $referencedTable, bool $withStandaloneActions = true): void
    {
        Schema::create($this->childTable, function (Blueprint $table) use ($referencedTable, $withStandaloneActions): void {
            $table->id();
            $reference = WorldReference::to($table, $referencedTable, 'nation_id')->nullable();

            if ($withStandaloneActions) {
                $reference
                    ->cascadeOnUpdateInStandalone()
                    ->restrictOnDeleteInStandalone();
            }
        });
    }

    private function foreignKeyDefinition(): ?object
    {
        return DB::selectOne(
            <<<'SQL'
                SELECT
                    kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
                    rc.UPDATE_RULE AS update_rule,
                    rc.DELETE_RULE AS delete_rule
                FROM information_schema.KEY_COLUMN_USAGE kcu
                INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                    AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
                    AND kcu.TABLE_NAME = ?
                    AND kcu.COLUMN_NAME = ?
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                SQL,
            [$this->childTable, 'nation_id'],
        );
    }

    private function assertColumnAndIndexShape(): void
    {
        $column = DB::selectOne(
            <<<'SQL'
                SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                SQL,
            [$this->childTable, 'nation_id'],
        );
        $indexCount = DB::scalar(
            <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                SQL,
            [$this->childTable, 'nation_id'],
        );

        $this->assertNotNull($column);
        $this->assertSame('bigint unsigned', $column->column_type);
        $this->assertSame('YES', $column->is_nullable);
        $this->assertGreaterThanOrEqual(1, (int) $indexCount);
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        return array_map(
            static fn (object $index): string => $index->index_name,
            DB::select(
                <<<'SQL'
                    SELECT DISTINCT INDEX_NAME AS index_name
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                    ORDER BY INDEX_NAME
                    SQL,
                [$this->childTable],
            ),
        );
    }
}
