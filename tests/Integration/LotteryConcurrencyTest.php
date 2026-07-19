<?php

namespace Tests\Integration;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryPurchase;
use App\Models\LotteryTicket;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMemberEligibilityService;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Throwable;

class LotteryConcurrencyTest extends MySqlIntegrationTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_mysql_harness_rejects_unexpected_outbound_http_requests(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://unexpected.example');
    }

    public function test_concurrent_purchases_cannot_exceed_the_nation_limit(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(10000000);
        $drawing = $this->openDrawing([
            'max_tickets_per_purchase' => 100,
            'max_tickets_per_nation' => 100,
        ]);

        $results = $this->runConcurrently([
            fn (): array => $this->purchaseResult($user, $account, $drawing, 60, (string) Str::uuid()),
            fn (): array => $this->purchaseResult($user, $account, $drawing, 60, (string) Str::uuid()),
        ]);

        $this->assertCount(1, collect($results)->where('status', 'ok'));
        $this->assertCount(1, collect($results)->where('class', ValidationException::class));
        $this->assertDatabaseCount('lottery_purchases', 1);
        $this->assertDatabaseCount('lottery_tickets', 60);
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertSame(60, $drawing->refresh()->ticket_count);
        $this->assertSame('7000000.00', number_format((float) $account->refresh()->money, 2, '.', ''));
    }

    public function test_concurrent_identical_requests_share_one_purchase(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $drawing = $this->openDrawing();
        $idempotencyKey = (string) Str::uuid();

        $results = $this->runConcurrently([
            fn (): array => $this->purchaseResult($user, $account, $drawing, 2, $idempotencyKey),
            fn (): array => $this->purchaseResult($user, $account, $drawing, 2, $idempotencyKey),
        ]);

        $this->assertSame(['ok', 'ok'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame($results[0]['ticket_ids'], $results[1]['ticket_ids']);
        $this->assertSame($results[0]['codes'], $results[1]['codes']);
        $this->assertDatabaseCount('lottery_purchases', 1);
        $this->assertDatabaseCount('lottery_tickets', 2);
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertSame(2, $drawing->refresh()->ticket_count);
        $this->assertSame('400000.00', number_format((float) $account->refresh()->money, 2, '.', ''));
        $this->assertSame($idempotencyKey, LotteryPurchase::query()->sole()->idempotency_key);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tickets_purchased')->count());
    }

    public function test_purchase_and_draw_at_the_closing_boundary_cannot_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-07-12 00:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $drawing = LotteryDrawing::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-07-05 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::now(),
        ]);

        $results = $this->runConcurrently([
            function () use ($drawing): array {
                $completed = app(LotteryService::class)->draw($drawing, CarbonImmutable::now());

                return ['drawing_status' => $completed->status];
            },
            fn (): array => $this->purchaseResult(
                $user,
                $account,
                $drawing,
                1,
                (string) Str::uuid(),
            ),
        ]);

        $this->assertCount(1, collect($results)->where('status', 'ok'));
        $this->assertCount(1, collect($results)->where('class', UserErrorException::class));
        $this->assertSame(LotteryDrawing::STATUS_DRAWN, $drawing->refresh()->status);
        $this->assertDatabaseCount('lottery_purchases', 0);
        $this->assertDatabaseCount('lottery_tickets', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
        $this->assertSame('500000.00', number_format((float) $account->refresh()->money, 2, '.', ''));
    }

    public function test_direct_repeated_draw_pays_the_winner_once(): void
    {
        CarbonImmutable::setTestNow('2026-07-12 00:00:00 UTC');
        [$user, $account] = $this->createParticipant(0);
        $drawing = LotteryDrawing::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-07-05 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::now(),
            'ticket_count' => 1,
            'next_ticket_sequence' => 1,
            'jackpot_amount' => 45000,
        ]);
        $winningCode = (new LotteryRandomizer)->ticketCodeForSequence($drawing->allocation_seed, 0);
        LotteryTicket::factory()->create([
            'lottery_drawing_id' => $drawing->id,
            'user_id' => $user->id,
            'nation_id' => $user->nation_id,
            'account_id' => $account->id,
            'code' => $winningCode,
        ]);
        $randomizer = Mockery::mock(LotteryRandomizer::class)->makePartial();
        $randomizer->shouldReceive('ticketCode')->once()->andReturn($winningCode);
        $service = new LotteryService(
            app(AllianceMemberEligibilityService::class),
            app(AuditLogger::class),
            $randomizer,
        );

        $first = $service->draw($drawing, CarbonImmutable::now());
        $second = $service->draw($drawing, CarbonImmutable::now());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($winningCode, $second->winning_code);
        $this->assertSame('45000.00', number_format((float) $account->refresh()->money, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'drawing_completed')->count());
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function createParticipant(float $money): array
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
        ]);
        $user = User::factory()->create(['nation_id' => $nation->id]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Concurrent Lottery Account';
        $account->money = $money;
        $account->frozen = false;
        $account->save();

        return [$user, $account];
    }

    /** @param array<string, mixed> $overrides */
    private function openDrawing(array $overrides = []): LotteryDrawing
    {
        return LotteryDrawing::factory()->create(array_merge([
            'starts_at' => CarbonImmutable::parse('2026-07-05 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-12 00:00:00 UTC'),
        ], $overrides));
    }

    /** @return array{ticket_ids: list<int>, codes: list<string>} */
    private function purchaseResult(
        User $user,
        Account $account,
        LotteryDrawing $drawing,
        int $quantity,
        string $idempotencyKey,
    ): array {
        $tickets = app(LotteryService::class)->purchaseTickets(
            $user,
            $account,
            $drawing,
            $quantity,
            $idempotencyKey,
        );

        return [
            'ticket_ids' => $tickets->modelKeys(),
            'codes' => $tickets->pluck('code')->all(),
        ];
    }

    /**
     * @param  list<Closure(): array<string, mixed>>  $workers
     * @return list<array<string, mixed>>
     */
    private function runConcurrently(array $workers): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The MySQL lottery concurrency suite requires the pcntl extension.');
        }

        $basePath = sys_get_temp_dir().'/nexus-lottery-'.Str::uuid();
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
                throw new RuntimeException('Unable to fork a lottery concurrency worker.');
            }

            if ($processId === 0) {
                while (! is_file($gatePath)) {
                    usleep(1000);
                    clearstatcache(true, $gatePath);
                }

                DB::purge('mysql');
                DB::reconnect('mysql');

                try {
                    $result = ['status' => 'ok', ...$worker()];
                } catch (ValidationException $exception) {
                    $result = [
                        'status' => 'error',
                        'class' => $exception::class,
                        'message' => $exception->getMessage(),
                        'errors' => $exception->errors(),
                    ];
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

            $processes[] = $processId;
        }

        file_put_contents($gatePath, 'go');

        foreach ($processes as $processId) {
            pcntl_waitpid($processId, $status);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException("Lottery concurrency worker [{$processId}] failed.");
            }
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            return array_map(function (string $resultPath): array {
                if (! is_file($resultPath)) {
                    throw new RuntimeException("Lottery concurrency result [{$resultPath}] was not written.");
                }

                return json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
            }, $resultPaths);
        } finally {
            @unlink($gatePath);

            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }
}
