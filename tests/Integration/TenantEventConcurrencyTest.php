<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\DataTransferObjects\TenantEvents\TenantEvent;
use App\Enums\TenantEventProcessingResult;
use App\Enums\TenantEventType;
use App\Events\WarDeclared;
use App\Models\War;
use App\Services\TenantEvents\TenantEventProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TenantEventConcurrencyTest extends MySqlIntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_concurrent_duplicate_delivery_commits_one_receipt_and_one_domain_effect(): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The tenant event concurrency proof requires the pcntl extension.');
        }

        CarbonImmutable::setTestNow('2026-08-08 22:00:05 UTC');
        Event::fake([WarDeclared::class]);
        $this->createWar();
        $event = $this->tenantEvent();
        $results = $this->runConcurrently([
            static fn (): string => app(TenantEventProcessor::class)->process($event)->value,
            static fn (): string => app(TenantEventProcessor::class)->process($event)->value,
        ]);

        sort($results, SORT_STRING);
        $this->assertSame([
            TenantEventProcessingResult::Duplicate->value,
            TenantEventProcessingResult::Processed->value,
        ], $results);
        $this->assertDatabaseCount('tenant_event_receipts', 1);
        $this->assertDatabaseCount('war_declaration_receipts', 1);
    }

    private function createWar(): War
    {
        return War::query()->create([
            'id' => 123456,
            'date' => now(),
            'reason' => 'Tenant event concurrency test',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => 10,
            'att_alliance_id' => 10014,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 20,
            'def_alliance_id' => 20028,
            'def_alliance_position' => 'OFFICER',
        ]);
    }

    private function tenantEvent(): TenantEvent
    {
        return new TenantEvent(
            deliveryId: '01KZHV17VQ9S6GDGBK0QJ5GF1Y',
            eventId: 'world:war:123456:create:v1',
            contractVersion: 1,
            tenantId: '01KZHV17VQ9S6GDGBK0QJ5GF1Z',
            type: TenantEventType::WarDeclared,
            subjectId: 123456,
            matchedAllianceIds: [10014],
            occurredAt: CarbonImmutable::parse('2026-08-08T22:00:00Z'),
            traceId: '01KZHV17VQ9S6GDGBK0QJ5GF20',
            bodyDigest: hash('sha256', 'tenant-event-concurrency-body'),
            transportNonce: str_repeat('a', 32),
            publishedAt: CarbonImmutable::parse('2026-08-08T22:00:05Z'),
        );
    }

    /**
     * @param  list<callable(): string>  $workers
     * @return list<string>
     */
    private function runConcurrently(array $workers): array
    {
        $basePath = sys_get_temp_dir().'/nexus-tenant-event-'.Str::uuid();
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
                throw new RuntimeException('Unable to fork a tenant event concurrency worker.');
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
                throw new RuntimeException("Tenant event concurrency worker [{$processId}] failed.");
            }
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            return array_map(function (string $path): string {
                $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);

                if (($result['status'] ?? null) !== 'ok' || ! is_string($result['result'] ?? null)) {
                    $class = is_string($result['class'] ?? null) ? $result['class'] : 'unknown';

                    throw new RuntimeException("Tenant event concurrency worker failed with [{$class}].");
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
