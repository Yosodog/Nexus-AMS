<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMemberEligibilityService;
use App\Services\AuditLogger;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class LotteryDrawingCapacityTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_full_code_space_draw_completes_within_128_megabytes(): void
    {
        ini_set('memory_limit', '128M');
        CarbonImmutable::setTestNow('2026-07-12 00:00:00 UTC');

        $nation = Nation::factory()->create();
        $user = User::factory()->create(['nation_id' => $nation->id]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Capacity Winner';
        $account->money = 0;
        $account->save();

        $drawing = LotteryDrawing::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-07-05 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-12 00:00:00 UTC'),
            'ticket_count' => 0,
            'next_ticket_sequence' => LotteryRandomizer::CODE_SPACE_SIZE,
            'jackpot_amount' => 0,
        ]);
        $allocator = new LotteryRandomizer;
        $winningCode = $allocator->ticketCodeForSequence(
            $drawing->allocation_seed,
            LotteryRandomizer::CODE_SPACE_SIZE - 1,
        );
        $timestamp = now();

        for ($start = 0; $start < LotteryRandomizer::CODE_SPACE_SIZE; $start += 500) {
            $quantity = min(500, LotteryRandomizer::CODE_SPACE_SIZE - $start);
            $rows = array_map(
                fn (string $code): array => [
                    'lottery_drawing_id' => $drawing->id,
                    'user_id' => $user->id,
                    'nation_id' => $nation->id,
                    'account_id' => $account->id,
                    'code' => $code,
                    'price_paid' => 50000,
                    'jackpot_contribution' => 45000,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $allocator->ticketCodesForRange($drawing->allocation_seed, $start, $quantity),
            );
            LotteryTicket::query()->insert($rows);
            unset($rows);
        }

        $randomizer = Mockery::mock(LotteryRandomizer::class)->makePartial();
        $randomizer->shouldReceive('ticketCode')->once()->andReturn($winningCode);
        $eligibilityService = Mockery::mock(AllianceMemberEligibilityService::class);
        $eligibilityService->shouldNotReceive('nationFor');
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once();
        $service = new LotteryService($eligibilityService, $auditLogger, $randomizer);

        $completed = $service->draw($drawing, CarbonImmutable::now());

        $this->assertSame(LotteryRandomizer::CODE_SPACE_SIZE, $completed->ticket_count);
        $this->assertSame($winningCode, $completed->winning_code);
        $this->assertSame('2099520000.00', $completed->jackpot_amount);
        $this->assertSame('2099520000.00', number_format((float) $account->refresh()->money, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 1);
    }
}
