<?php

namespace Tests\Feature\Integration;

use App\Jobs\AssignTaxBracket;
use App\Models\Account;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Services\GrowthCircleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Integration\MySqlIntegrationTestCase;
use Throwable;

class GrowthCircleEnrollmentConcurrencyTest extends MySqlIntegrationTestCase
{
    public function test_disenrollment_locks_shared_state_before_removing_enrollment(): void
    {
        Queue::fake();

        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Growth Circles';
        $account->save();

        $enrollment = GrowthCircleEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => 321,
            'enrolled_at' => now(),
        ]);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(GrowthCircleService::class)->disenroll($nation, logAudit: false);

        $nationLockIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from `nations`')
                && str_contains($sql, 'for update')
        );
        $enrollmentLockIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from `growth_circle_enrollments`')
                && str_contains($sql, 'for update')
        );
        $enrollmentDeleteIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'delete from `growth_circle_enrollments`')
        );

        $this->assertNotFalse($nationLockIndex);
        $this->assertNotFalse($enrollmentLockIndex);
        $this->assertNotFalse($enrollmentDeleteIndex);
        $this->assertTrue($nationLockIndex < $enrollmentLockIndex);
        $this->assertTrue($enrollmentLockIndex < $enrollmentDeleteIndex);
        $this->assertDatabaseMissing('growth_circle_enrollments', ['id' => $enrollment->id]);
        Queue::assertPushed(AssignTaxBracket::class, 1);
    }

    public function test_disenrollment_waits_for_the_city_grant_nation_lock(): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The Growth Circles concurrency suite requires the pcntl extension.');
        }

        Queue::fake();

        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Growth Circles';
        $account->save();

        $enrollment = GrowthCircleEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => 321,
            'enrolled_at' => now(),
        ]);

        $basePath = sys_get_temp_dir().'/nexus-growth-circle-'.Str::uuid();
        $startedPath = $basePath.'.started';
        $resultPath = $basePath.'.json';
        $processId = null;
        $childStatus = null;
        $childWasBlocked = false;
        $enrollmentExistedWhileLocked = false;

        DB::beginTransaction();

        try {
            Nation::query()
                ->whereKey($nation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Unable to fork the Growth Circles concurrency worker.');
            }

            if ($processId === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');
                file_put_contents($startedPath, 'started');

                try {
                    app(GrowthCircleService::class)->disenroll($nation, logAudit: false);
                    $result = ['status' => 'ok'];
                } catch (Throwable $exception) {
                    $result = [
                        'status' => 'error',
                        'class' => $exception::class,
                        'message' => $exception->getMessage(),
                    ];
                }

                file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }

            $deadline = microtime(true) + 5;

            while (! is_file($startedPath) && microtime(true) < $deadline) {
                usleep(1000);
                clearstatcache(true, $startedPath);
            }

            if (! is_file($startedPath)) {
                throw new RuntimeException('The Growth Circles concurrency worker did not start.');
            }

            usleep(500000);
            $childWasBlocked = pcntl_waitpid($processId, $childStatus, WNOHANG) === 0;
            $enrollmentExistedWhileLocked = GrowthCircleEnrollment::query()
                ->whereKey($enrollment->id)
                ->exists();
        } finally {
            DB::commit();
        }

        if ($childWasBlocked) {
            pcntl_waitpid($processId, $childStatus);
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            $this->assertTrue($childWasBlocked);
            $this->assertTrue($enrollmentExistedWhileLocked);
            $this->assertTrue(pcntl_wifexited($childStatus));
            $this->assertSame(0, pcntl_wexitstatus($childStatus));
            $this->assertFileExists($resultPath);
            $this->assertSame(
                ['status' => 'ok'],
                json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR),
            );
            $this->assertDatabaseMissing('growth_circle_enrollments', ['id' => $enrollment->id]);
        } finally {
            @unlink($startedPath);
            @unlink($resultPath);
        }
    }
}
