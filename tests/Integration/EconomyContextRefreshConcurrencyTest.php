<?php

namespace Tests\Integration;

use App\Models\Nation;
use App\Services\Economy\EconomyContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class EconomyContextRefreshConcurrencyTest extends MySqlIntegrationTestCase
{
    private const CONCURRENT_CONNECTION = 'mysql_economy_context_concurrent';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config([
            'services.pw.api_key' => 'test-key',
            'database.connections.'.self::CONCURRENT_CONNECTION => config('database.connections.mysql'),
        ]);

        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_contains($request->body(), 'treasures')
            ? Http::response(['data' => ['treasures' => [[
                'name' => 'Unrelated',
                'bonus' => 1,
                'nation_id' => 999999,
                'nation' => ['id' => 999999, 'alliance_id' => 999],
            ]]]])
            : Http::response(['data' => ['colors' => [
                ['color' => 'blue', 'turn_bonus' => 125],
                ['color' => 'red', 'turn_bonus' => 300],
            ]]]));
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONCURRENT_CONNECTION);
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function refresh_does_not_lock_nations_it_does_not_write(): void
    {
        $unchanged = $this->nation([
            'color_turn_bonus' => 125,
            'economy_context_synced_at' => now()->subHour(),
        ]);
        $changed = $this->nation([
            'color_turn_bonus' => null,
            'treasure_income_modifier' => null,
            'economy_context_synced_at' => null,
        ]);
        $deleted = $this->nation();
        $deleted->delete();
        $unchangedStamp = $unchanged->economy_context_synced_at;

        $concurrentWrites = [];
        $connection = $this->concurrentConnection();
        DB::listen(function (QueryExecuted $query) use (
            $connection,
            $unchanged,
            $deleted,
            &$concurrentWrites
        ): void {
            if ($query->connectionName !== 'mysql' || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            // The refresh now holds its write locks. Every other row, and the gap past the last
            // nation, must stay writable from another connection.
            $concurrentWrites['unchanged'] = $this->timed(fn () => $connection->table('nations')
                ->where('id', $unchanged->id)
                ->update(['score' => 4321]));
            $concurrentWrites['soft_deleted'] = $this->timed(fn () => $connection->table('nations')
                ->where('id', $deleted->id)
                ->update(['score' => 1234]));
            $concurrentWrites['new_nation'] = $this->timed(fn () => $connection->table('nations')
                ->insert(Nation::factory()->make(['id' => 990_001])->getAttributes()));
        });

        $count = app(EconomyContextService::class)->refresh();

        $this->assertSame(2, $count);
        $this->assertSame(['unchanged', 'soft_deleted', 'new_nation'], array_keys($concurrentWrites));

        foreach ($concurrentWrites as $label => $write) {
            $this->assertNull($write['error'], "The concurrent {$label} write was blocked: {$write['error']}");
            $this->assertLessThan(0.5, $write['seconds'], "The concurrent {$label} write waited on a lock.");
        }

        $unchanged->refresh();
        $this->assertSame(4321.0, (float) $unchanged->score);
        $this->assertTrue($unchanged->economy_context_synced_at->equalTo($unchangedStamp));
        $this->assertSame(125, $changed->refresh()->color_turn_bonus);
        $this->assertSame(1234.0, (float) Nation::withTrashed()->findOrFail($deleted->id)->score);
        $this->assertNull(Nation::query()->findOrFail(990_001)->economy_context_synced_at);
    }

    #[Test]
    public function a_concurrent_nation_update_is_kept_and_its_new_color_drives_the_context(): void
    {
        $nation = $this->nation([
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => now()->subHour(),
        ]);

        $connection = $this->concurrentConnection();
        $updatedConcurrently = false;
        DB::listen(function (QueryExecuted $query) use ($connection, $nation, &$updatedConcurrently): void {
            $sql = strtolower($query->sql);

            if (
                $updatedConcurrently
                || $query->connectionName !== 'mysql'
                || ! str_contains($sql, 'from `nations`')
                || ! str_contains($sql, 'limit 1000')
            ) {
                return;
            }

            // Between the refresh's unlocked read and its write, the nation sync moves the nation
            // to another color and clears its context, exactly as SyncNationsJob does.
            $updatedConcurrently = true;
            $connection->table('nations')->where('id', $nation->id)->update([
                'color' => 'red',
                'score' => 5555,
                'treasure_income_modifier' => null,
                'color_turn_bonus' => null,
                'economy_context_synced_at' => null,
            ]);
        });

        $this->assertSame(1, app(EconomyContextService::class)->refresh());

        $nation->refresh();
        $this->assertTrue($updatedConcurrently);
        $this->assertSame('red', $nation->color);
        $this->assertSame(5555.0, (float) $nation->score);
        $this->assertSame(300, $nation->color_turn_bonus);
        $this->assertTrue($nation->economy_context_synced_at->equalTo(CarbonImmutable::now()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function nation(array $overrides = []): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 125,
            'economy_context_synced_at' => now(),
            ...$overrides,
        ]);
    }

    private function concurrentConnection(): Connection
    {
        $connection = DB::connection(self::CONCURRENT_CONNECTION);
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $connection;
    }

    /**
     * @return array{seconds: float, error: string|null}
     */
    private function timed(callable $write): array
    {
        $startedAt = microtime(true);

        try {
            $write();
            $error = null;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return ['seconds' => microtime(true) - $startedAt, 'error' => $error];
    }
}
