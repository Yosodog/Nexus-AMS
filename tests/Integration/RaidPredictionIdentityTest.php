<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\War;
use App\Services\RaidPredictionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class RaidPredictionIdentityTest extends MySqlIntegrationTestCase
{
    #[DataProvider('transactionIsolationLevels')]
    public function test_concurrent_declarations_keep_one_baseline_and_attack_ids_are_unique(string $isolation): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The raid identity proof requires pcntl.');
        }
        Cache::forever('alliances:membership:ids', [777]);
        Queue::fake();
        $war = War::query()->create([
            'id' => 700123, 'date' => now(), 'reason' => 'Raid identity test',
            'war_type' => 'RAID', 'turns_left' => 60,
            'att_id' => 10, 'def_id' => 20, 'att_alliance_id' => 777, 'def_alliance_id' => 0,
            'att_alliance_position' => 'MEMBER', 'def_alliance_position' => 'MEMBER',
        ]);
        $capture = static function () use ($isolation): string {
            DB::connection('mysql')->statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);

            return (string) app(RaidPredictionService::class)->captureWar(War::query()->findOrFail(700123))->id;
        };
        $results = $this->runConcurrently([$capture, $capture]);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('raid_predictions', 1);
        $prediction = RaidPrediction::query()->firstOrFail();
        $baseline = $prediction->target_snapshot;
        $this->assertSame('incomplete', $prediction->capture_status);
        $this->assertSame($baseline, app(RaidPredictionService::class)->captureWar($war)->target_snapshot);

        $evidence = ['war_id' => $war->id, 'attack_id' => 80123, 'observed_at' => now()];
        RaidOutcomeAttack::query()->create($evidence);
        try {
            RaidOutcomeAttack::query()->create(array_replace($evidence, ['war_id' => 700124]));
            $this->fail('One game attack identity was accepted in two wars.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('raid_outcome_attacks', 1);
        }
    }

    /** @return array<string, array{string}> */
    public static function transactionIsolationLevels(): array
    {
        return ['repeatable read' => ['REPEATABLE READ'], 'read committed' => ['READ COMMITTED']];
    }

    /**
     * @param  list<callable(): string>  $workers
     * @return list<string>
     */
    private function runConcurrently(array $workers): array
    {
        $basePath = sys_get_temp_dir().'/nexus-raid-identity-'.Str::uuid();
        $gatePath = $basePath.'.gate';
        $resultPaths = [];
        $processes = [];

        DB::disconnect('mysql');
        DB::purge('mysql');

        foreach ($workers as $index => $worker) {
            $resultPath = $basePath.'.'.$index.'.json';
            $resultPaths[] = $resultPath;
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Unable to fork a raid identity concurrency worker.');
            }

            if ($processId === 0) {
                while (! is_file($gatePath)) {
                    usleep(1_000);
                    clearstatcache(true, $gatePath);
                }

                DB::purge('mysql');
                DB::reconnect('mysql');

                try {
                    $result = ['status' => 'ok', 'result' => $worker()];
                } catch (Throwable $exception) {
                    $result = ['status' => 'error', 'class' => $exception::class];
                }

                file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }

            $processes[] = $processId;
        }

        file_put_contents($gatePath, 'go');

        foreach ($processes as $processId) {
            pcntl_waitpid($processId, $status);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException("Raid identity concurrency worker [{$processId}] failed.");
            }
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            return array_map(function (string $path): string {
                $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);

                if (($result['status'] ?? null) !== 'ok' || ! is_string($result['result'] ?? null)) {
                    $class = is_string($result['class'] ?? null) ? $result['class'] : 'unknown';

                    throw new RuntimeException("Raid identity concurrency worker failed with [{$class}].");
                }

                return $result['result'];
            }, $resultPaths);
        } finally {
            @unlink($gatePath);

            foreach ($resultPaths as $path) {
                @unlink($path);
            }
        }
    }
}
