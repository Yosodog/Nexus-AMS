<?php

namespace Tests\Feature\Workflows;

use App\Exceptions\UserErrorException;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Livewire\AppHeader;
use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use App\Models\Nation;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AllianceMemberEligibilityService;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class LotteryWorkflowTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_member_can_purchase_random_three_character_tickets_for_fifty_thousand_each(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $randomizer = new LotteryRandomizer;
        $service = $this->lotteryService($randomizer, membershipChecks: 1, auditEvents: 1);

        $tickets = $service->purchaseTickets($user, $account, 2, '127.0.0.1');

        $drawing = LotteryDrawing::query()->sole();
        $this->assertSame(90, LotteryTicket::JACKPOT_PERCENTAGE);
        $this->assertSame('2026-07-05 00:00:00', $drawing->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-12 00:00:00', $drawing->ends_at->format('Y-m-d H:i:s'));
        $this->assertCount(2, $tickets->pluck('code')->unique());
        $this->assertSame([], $tickets->reject(
            fn (LotteryTicket $ticket): bool => preg_match('/^[0-9A-Z]{3}$/', $ticket->code) === 1,
        )->all());
        $this->assertSame(['45000.00', '45000.00'], $tickets->pluck('jackpot_contribution')->all());
        $this->assertAccountMoney($account, 400000);
        $this->assertSame(2, $drawing->ticket_count);
        $this->assertSame(2, $drawing->next_ticket_sequence);
        $this->assertSame('90000.00', $drawing->jackpot_amount);
        $this->assertSame('50000.00', $drawing->ticket_price);
        $this->assertDatabaseHas('manual_transactions', [
            'account_id' => $account->id,
            'admin_id' => $user->id,
            'money' => -100000,
            'note' => 'Weekly lottery ticket purchase',
        ]);
        $this->assertDatabaseCount('lottery_tickets', 2);
    }

    public function test_successive_purchases_reserve_disjoint_permutation_ranges(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $randomizer = new LotteryRandomizer;
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 2);

        $firstTickets = $service->purchaseTickets($user, $account, 2);
        $secondTickets = $service->purchaseTickets($user, $account, 2);
        $drawing = LotteryDrawing::query()->sole();
        $expectedCodes = $randomizer->ticketCodesForRange($drawing->allocation_seed, 0, 4);

        $this->assertSame($expectedCodes, $firstTickets->concat($secondTickets)->pluck('code')->all());
        $this->assertCount(4, LotteryTicket::query()->where('lottery_drawing_id', $drawing->id)->get()->unique('code'));
        $this->assertSame(4, $drawing->next_ticket_sequence);
        $this->assertSame('180000.00', $drawing->jackpot_amount);
        $this->assertAccountMoney($account, 300000);
    }

    public function test_insufficient_funds_are_rejected_without_creating_a_ticket_or_moving_money(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(49999.99);
        $service = $this->lotteryService(new LotteryRandomizer, membershipChecks: 1, auditEvents: 0);

        try {
            $service->purchaseTickets($user, $account, 1);
            $this->fail('The lottery accepted a purchase without sufficient funds.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['The selected account does not have enough money for this purchase.'],
                $exception->errors()['account_id'],
            );
        }

        $this->assertAccountMoney($account, 49999.99);
        $this->assertDatabaseCount('lottery_tickets', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_frozen_account_is_rejected_without_creating_a_ticket(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000, frozen: true);
        $service = $this->lotteryService(new LotteryRandomizer, membershipChecks: 1, auditEvents: 0);

        try {
            $service->purchaseTickets($user, $account, 1);
            $this->fail('The lottery accepted a purchase from a frozen account.');
        } catch (UserErrorException $exception) {
            $this->assertSame('Frozen accounts cannot be used to buy lottery tickets.', $exception->getMessage());
        }

        $this->assertAccountMoney($account, 500000);
        $this->assertDatabaseCount('lottery_tickets', 0);
    }

    public function test_request_validation_rejects_another_members_account(): void
    {
        [$user] = $this->createParticipant(500000);
        [, $otherAccount] = $this->createParticipant(500000);
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
            ])
            ->post(route('lottery.tickets.store'), [
                'account_id' => $otherAccount->id,
                'quantity' => 1,
            ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('lottery_tickets', 0);
    }

    public function test_applicant_cannot_view_or_purchase_lottery_tickets(): void
    {
        [$applicant, $account] = $this->createParticipant(500000, alliancePosition: 'APPLICANT');
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();

        $this->actingAs($applicant)
            ->withoutMiddleware([
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
            ])
            ->get(route('lottery.index'))
            ->assertForbidden();

        $this->actingAs($applicant)
            ->withoutMiddleware([
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
            ])
            ->post(route('lottery.tickets.store'), [
                'account_id' => $account->id,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lottery_tickets', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_service_rejects_a_user_when_member_eligibility_fails(): void
    {
        [$user, $account] = $this->createParticipant(500000);
        $eligibilityService = Mockery::mock(AllianceMemberEligibilityService::class);
        $eligibilityService->shouldReceive('nationFor')
            ->once()
            ->with($user)
            ->andThrow(new AuthorizationException('Members only.'));
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldNotReceive('recordAfterCommit');
        $service = new LotteryService($eligibilityService, $auditLogger, new LotteryRandomizer);

        $this->expectException(AuthorizationException::class);

        $service->purchaseTickets($user, $account, 1);
    }

    public function test_applicant_navigation_does_not_show_the_lottery_link(): void
    {
        [$applicant] = $this->createParticipant(500000, alliancePosition: 'APPLICANT');
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();

        Livewire::actingAs($applicant)
            ->test(AppHeader::class)
            ->assertDontSee('Weekly lottery');
    }

    public function test_ticket_limit_is_shared_by_every_user_and_account_for_a_nation(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$firstUser, $firstAccount] = $this->createParticipant(500000);
        $secondUser = User::factory()->create(['nation_id' => $firstUser->nation_id]);
        $secondAccount = new Account;
        $secondAccount->nation_id = $firstUser->nation_id;
        $secondAccount->name = 'Second Lottery Account';
        $secondAccount->money = 500000;
        $secondAccount->save();

        $randomizer = new LotteryRandomizer;
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 1);
        $drawing = $service->currentDrawing();
        $this->seedLotteryTickets(
            $drawing,
            $firstUser,
            $firstAccount,
            LotteryService::MAX_TICKETS_PER_NATION - 1,
            $randomizer,
        );

        $service->purchaseTickets($secondUser, $secondAccount, 1);

        try {
            $service->purchaseTickets($firstUser, $firstAccount, 1);
            $this->fail('The lottery allowed a nation to exceed its drawing limit.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Your nation has reached the ticket limit for this drawing.'],
                $exception->errors()['quantity'],
            );
        }

        $this->assertSame(
            LotteryService::MAX_TICKETS_PER_NATION,
            LotteryTicket::query()
                ->where('lottery_drawing_id', $drawing->id)
                ->where('nation_id', $firstUser->nation_id)
                ->count(),
        );
        $this->assertDatabaseHas('lottery_tickets', [
            'user_id' => $secondUser->id,
            'nation_id' => $firstUser->nation_id,
            'account_id' => $secondAccount->id,
        ]);
    }

    public function test_purchase_route_is_throttled_per_nation(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(1000000);
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();

        $client = $this->actingAs($user)->withoutMiddleware([
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $client->post(route('lottery.tickets.store'), [
                'account_id' => $account->id,
                'quantity' => 1,
            ])->assertRedirect();
        }

        $client->post(route('lottery.tickets.store'), [
            'account_id' => $account->id,
            'quantity' => 1,
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('lottery_tickets', 10);
        $this->assertAccountMoney($account, 500000);
    }

    public function test_expired_drawing_pays_the_matching_ticket_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$firstUser, $firstAccount] = $this->createParticipant(500000);
        [$secondUser, $secondAccount] = $this->createParticipant(500000);
        $randomizer = Mockery::mock(LotteryRandomizer::class)->makePartial();
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 3);

        $service->purchaseTickets($firstUser, $firstAccount, 1);
        $winningTicket = $service->purchaseTickets($secondUser, $secondAccount, 2)->last();
        $randomizer->shouldReceive('ticketCode')->once()->andReturn($winningTicket->code);

        CarbonImmutable::setTestNow('2026-07-12 00:00:00 UTC');
        $drawings = $service->drawExpiredDrawings();
        $drawing = $drawings->sole();

        $this->assertSame(LotteryDrawing::STATUS_DRAWN, $drawing->status);
        $this->assertSame($winningTicket->code, $drawing->winning_code);
        $this->assertSame($winningTicket->code, $drawing->winningTicket->code);
        $this->assertSame('135000.00', $drawing->jackpot_amount);
        $this->assertAccountMoney($firstAccount, 450000);
        $this->assertAccountMoney($secondAccount, 535000);
        $this->assertDatabaseCount('manual_transactions', 3);

        $this->assertCount(0, $service->drawExpiredDrawings());
        $this->assertAccountMoney($secondAccount, 535000);
        $this->assertDatabaseCount('manual_transactions', 3);
    }

    public function test_unmatched_code_rolls_the_full_pool_into_the_next_week(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $randomizer = Mockery::mock(LotteryRandomizer::class)->makePartial();
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 3);
        $purchasedTickets = $service->purchaseTickets($user, $account, 2);
        $unmatchedCode = collect(['000', '001', '002'])
            ->first(fn (string $code): bool => ! $purchasedTickets->contains('code', $code));
        $randomizer->shouldReceive('ticketCode')->once()->andReturn($unmatchedCode);

        CarbonImmutable::setTestNow('2026-07-12 00:00:00 UTC');
        $completedDrawing = $service->drawExpiredDrawings()->sole();
        $nextDrawing = LotteryDrawing::query()
            ->where('starts_at', $completedDrawing->ends_at)
            ->sole();

        $this->assertSame($unmatchedCode, $completedDrawing->winning_code);
        $this->assertNull($completedDrawing->winning_ticket_id);
        $this->assertSame('90000.00', $completedDrawing->jackpot_amount);
        $this->assertSame(LotteryDrawing::STATUS_OPEN, $nextDrawing->status);
        $this->assertSame('90000.00', $nextDrawing->rollover_amount);
        $this->assertSame('90000.00', $nextDrawing->jackpot_amount);
        $this->assertAccountMoney($account, 400000);
        $this->assertDatabaseCount('manual_transactions', 1);

        $nextTicket = $service->purchaseTickets($user, $account, 1)->sole();

        $this->assertSame(
            $randomizer->ticketCodeForSequence($nextDrawing->allocation_seed, 0),
            $nextTicket->code,
        );
        $this->assertSame('45000.00', $nextTicket->jackpot_contribution);
        $this->assertSame('135000.00', $nextDrawing->refresh()->jackpot_amount);
        $this->assertSame('90000.00', $nextDrawing->rollover_amount);
        $this->assertSame(1, $nextDrawing->ticket_count);
        $this->assertAccountMoney($account, 350000);
        $this->assertDatabaseCount('manual_transactions', 2);
    }

    public function test_expired_drawing_with_no_tickets_closes_without_a_payout(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        $randomizer = Mockery::mock(LotteryRandomizer::class)->makePartial();
        $randomizer->shouldReceive('ticketCode')->once()->andReturn('XYZ');
        $service = $this->lotteryService($randomizer, membershipChecks: 0, auditEvents: 1);
        $drawing = $service->currentDrawing();

        CarbonImmutable::setTestNow('2026-07-12 00:00:00 UTC');
        $drawnDrawing = $service->drawExpiredDrawings()->sole();

        $this->assertTrue($drawing->is($drawnDrawing));
        $this->assertSame(LotteryDrawing::STATUS_DRAWN, $drawnDrawing->status);
        $this->assertSame('XYZ', $drawnDrawing->winning_code);
        $this->assertNull($drawnDrawing->winning_ticket_id);
        $this->assertSame('0.00', $drawnDrawing->jackpot_amount);
        $this->assertDatabaseCount('lottery_drawings', 1);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_final_available_code_is_allocated_and_later_purchases_are_rejected_as_sold_out(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(100000);
        $randomizer = new LotteryRandomizer;
        $drawing = LotteryDrawing::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-07-05 00:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-12 00:00:00 UTC'),
            'ticket_count' => LotteryRandomizer::CODE_SPACE_SIZE - 1,
            'next_ticket_sequence' => LotteryRandomizer::CODE_SPACE_SIZE - 1,
        ]);
        $expectedFinalCode = $randomizer->ticketCodeForSequence(
            $drawing->allocation_seed,
            LotteryRandomizer::CODE_SPACE_SIZE - 1,
        );
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 1);

        $ticket = $service->purchaseTickets($user, $account, 1)->sole();

        $this->assertSame($expectedFinalCode, $ticket->code);
        $this->assertSame(LotteryRandomizer::CODE_SPACE_SIZE, $drawing->refresh()->ticket_count);
        $this->assertSame(LotteryRandomizer::CODE_SPACE_SIZE, $drawing->next_ticket_sequence);
        $this->assertAccountMoney($account, 50000);

        try {
            $service->purchaseTickets($user, $account, 1);
            $this->fail('The lottery sold a ticket after exhausting the code space.');
        } catch (ValidationException $exception) {
            $this->assertSame(['This lottery drawing is sold out.'], $exception->errors()['quantity']);
        }

        $this->assertDatabaseCount('lottery_tickets', 1);
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertAccountMoney($account, 50000);
    }

    public function test_account_with_an_open_ticket_cannot_be_deleted_before_the_draw(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(50000);
        $service = $this->lotteryService(new LotteryRandomizer, membershipChecks: 1, auditEvents: 1);
        $service->purchaseTickets($user, $account, 1);

        try {
            AccountService::deleteAccount($account, $user->nation_id);
            $this->fail('An account with an open lottery ticket was deleted.');
        } catch (UserErrorException $exception) {
            $this->assertSame('The account has tickets in an open lottery drawing.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted($account);
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function createParticipant(
        float $money,
        bool $frozen = false,
        string $alliancePosition = 'MEMBER',
    ): array {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => $alliancePosition,
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
        ]);
        $user = User::factory()->create(['nation_id' => $nation->id]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Lottery Account '.$nation->id;
        $account->money = $money;
        $account->frozen = $frozen;
        $account->save();

        return [$user, $account];
    }

    private function lotteryService(
        LotteryRandomizer $randomizer,
        int $membershipChecks,
        int $auditEvents,
    ): LotteryService {
        $eligibilityService = Mockery::mock(AllianceMemberEligibilityService::class);
        $eligibilityExpectation = $eligibilityService->shouldReceive('nationFor');

        if ($membershipChecks > 0) {
            $eligibilityExpectation
                ->times($membershipChecks)
                ->with(Mockery::type(User::class))
                ->andReturnUsing(fn (User $user): Nation => $user->nation);
        } else {
            $eligibilityExpectation->never();
        }

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditExpectation = $auditLogger->shouldReceive('recordAfterCommit');

        if ($auditEvents > 0) {
            $auditExpectation->times($auditEvents);
        } else {
            $auditExpectation->never();
        }

        return new LotteryService($eligibilityService, $auditLogger, $randomizer);
    }

    private function seedLotteryTickets(
        LotteryDrawing $drawing,
        User $user,
        Account $account,
        int $quantity,
        LotteryRandomizer $randomizer,
    ): void {
        $timestamp = now();

        collect($randomizer->ticketCodesForRange($drawing->allocation_seed, 0, $quantity))
            ->chunk(500)
            ->each(function ($codes) use ($account, $drawing, $timestamp, $user): void {
                LotteryTicket::query()->insert($codes->map(fn (string $code): array => [
                    'lottery_drawing_id' => $drawing->id,
                    'user_id' => $user->id,
                    'nation_id' => $user->nation_id,
                    'account_id' => $account->id,
                    'code' => $code,
                    'price_paid' => LotteryTicket::PRICE,
                    'jackpot_contribution' => LotteryTicket::JACKPOT_CONTRIBUTION,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
            });

        $drawing->update([
            'ticket_count' => $quantity,
            'next_ticket_sequence' => $quantity,
            'jackpot_amount' => LotteryTicket::JACKPOT_CONTRIBUTION * $quantity,
        ]);
    }

    private function assertAccountMoney(Account $account, float $expected): void
    {
        $this->assertSame(
            number_format($expected, 2, '.', ''),
            number_format((float) $account->refresh()->money, 2, '.', ''),
        );
    }
}
