<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\User;
use App\Models\War;
use App\Models\WarMapAlertState;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use App\Services\War\WarMapAlertService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\ConfiguresDiscordQueueV2;
use Throwable;

class WarMapAlertConcurrencyTest extends MySqlIntegrationTestCase
{
    use ConfiguresDiscordQueueV2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordQueueV2();
        SettingService::setDiscordPrivateNotificationsEnabled(true);
    }

    public function test_concurrent_initial_evaluation_creates_one_state_and_one_queue_row(): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The war map alert concurrency proof requires the pcntl extension.');
        }

        [$user, , $war] = $this->createScenario();
        $userId = (int) $user->id;
        $warId = (int) $war->id;

        $results = $this->runConcurrently([
            static fn (): bool => app(WarMapAlertService::class)->evaluate(
                User::query()->findOrFail($userId),
                War::query()->findOrFail($warId),
            ),
            static fn (): bool => app(WarMapAlertService::class)->evaluate(
                User::query()->findOrFail($userId),
                War::query()->findOrFail($warId),
            ),
        ]);

        sort($results);
        $this->assertSame([false, true], $results);
        $this->assertDatabaseCount('war_map_alert_states', 1);
        $this->assertDatabaseCount('discord_queue', 1);

        $state = WarMapAlertState::query()->sole();
        $this->assertSame($userId, (int) $state->user_id);
        $this->assertSame((int) $user->nation_id, (int) $state->nation_id);
        $this->assertSame($warId, (int) $state->war_id);
        $this->assertFalse((bool) $state->armed);
        $this->assertSame(1, (int) $state->notification_sequence);

        $queue = DiscordQueue::query()->sole();
        $this->assertSame('war-map:'.$state->id.':1', $queue->payload['notification_id']);
    }

    public function test_concurrent_rearmed_evaluation_creates_one_additional_notification(): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The war map alert concurrency proof requires the pcntl extension.');
        }

        [$user, , $war] = $this->createScenario();
        $service = app(WarMapAlertService::class);

        $this->assertTrue($service->evaluate($user, $war));
        $this->assertFalse($service->evaluate($user->fresh(), $war->fresh()));

        $war->forceFill(['att_points' => 11])->save();
        $this->assertFalse($service->evaluate($user->fresh(), $war->fresh()));

        $war->forceFill(['att_points' => 12])->save();
        $userId = (int) $user->id;
        $warId = (int) $war->id;
        $results = $this->runConcurrently([
            static fn (): bool => app(WarMapAlertService::class)->evaluate(
                User::query()->findOrFail($userId),
                War::query()->findOrFail($warId),
            ),
            static fn (): bool => app(WarMapAlertService::class)->evaluate(
                User::query()->findOrFail($userId),
                War::query()->findOrFail($warId),
            ),
        ]);

        sort($results);
        $this->assertSame([false, true], $results);
        $this->assertDatabaseCount('war_map_alert_states', 1);
        $this->assertDatabaseCount('discord_queue', 2);

        $state = WarMapAlertState::query()->sole();
        $this->assertFalse((bool) $state->armed);
        $this->assertSame(2, (int) $state->notification_sequence);

        $notificationIds = DiscordQueue::query()
            ->get()
            ->map(fn (DiscordQueue $queue): string => (string) $queue->payload['notification_id'])
            ->all();

        $this->assertCount(2, array_unique($notificationIds));
        $this->assertContains('war-map:'.$state->id.':1', $notificationIds);
        $this->assertContains('war-map:'.$state->id.':2', $notificationIds);
    }

    public function test_state_identity_unique_index_rejects_duplicate_rows(): void
    {
        [$user, $nation, $war] = $this->createScenario();
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        $attributes = [
            'user_id' => $user->id,
            'nation_id' => $nation->id,
            'war_id' => $war->id,
            'armed' => true,
            'notification_sequence' => 0,
        ];

        DB::table('war_map_alert_states')->insert($attributes + $timestamps);

        try {
            DB::table('war_map_alert_states')->insert($attributes + $timestamps);
            $this->fail('The war map alert state identity index accepted a duplicate row.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) ($exception->errorInfo[0] ?? ''));
        }
    }

    /**
     * @return array{0: User, 1: Nation, 2: War}
     */
    private function createScenario(): array
    {
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->refresh();

        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $opponent = Nation::factory()->create();
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => (string) fake()->unique()->numerify('##################'),
            'unlinked_at' => null,
        ]);

        $war = War::query()->create([
            'id' => 123456,
            'date' => now(),
            'reason' => 'War map alert concurrency test',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => $nation->id,
            'att_alliance_id' => $alliance->id,
            'att_alliance_position' => 'MEMBER',
            'att_points' => 12,
            'def_id' => $opponent->id,
            'def_alliance_id' => $opponent->alliance_id,
            'def_alliance_position' => 'MEMBER',
        ]);

        return [$user, $nation, $war];
    }

    /**
     * @param  list<callable(): bool>  $workers
     * @return list<bool>
     */
    private function runConcurrently(array $workers): array
    {
        $basePath = sys_get_temp_dir().'/nexus-war-map-alert-'.Str::uuid();
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
                throw new RuntimeException('Unable to fork a war map alert concurrency worker.');
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
                throw new RuntimeException("War map alert concurrency worker [{$processId}] failed.");
            }
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            return array_map(function (string $path): bool {
                $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);

                if (($result['status'] ?? null) !== 'ok'
                    || ! array_key_exists('result', $result)
                    || ! is_bool($result['result'])) {
                    $class = is_string($result['class'] ?? null) ? $result['class'] : 'unknown';

                    throw new RuntimeException("War map alert concurrency worker failed with [{$class}].");
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
