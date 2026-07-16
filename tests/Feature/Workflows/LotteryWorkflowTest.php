<?php

namespace Tests\Feature\Workflows;

use App\Exceptions\UserErrorException;
use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Livewire\AppHeader;
use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryPurchase;
use App\Models\LotteryTicket;
use App\Models\ManualTransaction;
use App\Models\Nation;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AllianceMemberEligibilityService;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
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

        $tickets = $this->purchaseTickets($service, $user, $account, 2, '127.0.0.1');

        $drawing = LotteryDrawing::query()->sole();
        $this->assertSame(SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS, $drawing->jackpot_basis_points);
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
            'admin_id' => null,
            'money' => -100000,
            'note' => 'Weekly lottery ticket purchase',
        ]);
        $transaction = ManualTransaction::query()->sole();
        $this->assertSame('member', $transaction->meta['initiated_by_type']);
        $this->assertSame($user->id, $transaction->meta['initiated_by_user_id']);
        $this->assertDatabaseCount('lottery_tickets', 2);
    }

    public function test_successive_purchases_reserve_disjoint_permutation_ranges(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $randomizer = new LotteryRandomizer;
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 2);

        $firstTickets = $this->purchaseTickets($service, $user, $account, 2);
        $secondTickets = $this->purchaseTickets($service, $user, $account, 2);
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
            $this->purchaseTickets($service, $user, $account, 1);
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
            $this->purchaseTickets($service, $user, $account, 1);
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
        $drawing = app(LotteryService::class)->currentDrawing();

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
            ])
            ->post(route('lottery.tickets.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'drawing_id' => $drawing->id,
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
        $auditLogger->shouldNotReceive('record');
        $service = new LotteryService($eligibilityService, $auditLogger, new LotteryRandomizer);

        $this->expectException(AuthorizationException::class);

        $this->purchaseTickets($service, $user, $account, 1);
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
            SettingService::MAX_LOTTERY_TICKETS_PER_NATION - 1,
            $randomizer,
        );

        $this->purchaseTickets($service, $secondUser, $secondAccount, 1);

        try {
            $this->purchaseTickets($service, $firstUser, $firstAccount, 1);
            $this->fail('The lottery allowed a nation to exceed its drawing limit.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Your nation has reached the ticket limit for this drawing.'],
                $exception->errors()['quantity'],
            );
        }

        $this->assertSame(
            SettingService::MAX_LOTTERY_TICKETS_PER_NATION,
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
                'idempotency_key' => (string) Str::uuid(),
                'account_id' => $account->id,
                'drawing_id' => app(LotteryService::class)->currentDrawing()->id,
                'quantity' => 1,
            ])->assertRedirect();
        }

        $client->post(route('lottery.tickets.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $account->id,
            'drawing_id' => app(LotteryService::class)->currentDrawing()->id,
            'quantity' => 1,
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('lottery_tickets', 10);
        $this->assertAccountMoney($account, 500000);
    }

    public function test_stale_form_cannot_purchase_from_a_new_drawing(): void
    {
        CarbonImmutable::setTestNow('2026-07-11 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();
        $displayedDrawing = app(LotteryService::class)->currentDrawing();

        CarbonImmutable::setTestNow('2026-07-12 00:01:00 UTC');

        $this->actingAs($user)
            ->withoutMiddleware([
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
            ])
            ->post(route('lottery.tickets.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'drawing_id' => $displayedDrawing->id,
                'account_id' => $account->id,
                'quantity' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('lottery_drawings', 1);
        $this->assertDatabaseCount('lottery_tickets', 0);
        $this->assertAccountMoney($account, 500000);
    }

    public function test_identical_purchase_retry_returns_original_tickets_without_a_second_charge(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $service = $this->lotteryService(new LotteryRandomizer, membershipChecks: 2, auditEvents: 1);
        $drawing = $service->currentDrawing();
        $idempotencyKey = (string) Str::uuid();

        $first = $this->purchaseTickets(
            $service,
            $user,
            $account,
            2,
            drawing: $drawing,
            idempotencyKey: $idempotencyKey,
        );
        $retry = $this->purchaseTickets(
            $service,
            $user,
            $account,
            2,
            drawing: $drawing,
            idempotencyKey: $idempotencyKey,
        );

        $this->assertSame($first->modelKeys(), $retry->modelKeys());
        $this->assertSame($first->pluck('code')->all(), $retry->pluck('code')->all());
        $this->assertSame($idempotencyKey, LotteryPurchase::query()->sole()->idempotency_key);
        $this->assertNotNull(LotteryPurchase::query()->sole()->manual_transaction_id);
        $this->assertAccountMoney($account, 400000);
        $this->assertDatabaseCount('lottery_purchases', 1);
        $this->assertDatabaseCount('lottery_tickets', 2);
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertSame(2, $drawing->refresh()->ticket_count);
    }

    public function test_idempotency_key_cannot_be_reused_for_different_purchase_details(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $service = $this->lotteryService(new LotteryRandomizer, membershipChecks: 2, auditEvents: 1);
        $drawing = $service->currentDrawing();
        $idempotencyKey = (string) Str::uuid();

        $this->purchaseTickets(
            $service,
            $user,
            $account,
            1,
            drawing: $drawing,
            idempotencyKey: $idempotencyKey,
        );

        try {
            $this->purchaseTickets(
                $service,
                $user,
                $account,
                2,
                drawing: $drawing,
                idempotencyKey: $idempotencyKey,
            );
            $this->fail('The lottery reused an idempotency key with different details.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This purchase request identifier was already used for different lottery details.'],
                $exception->errors()['idempotency_key'],
            );
        }

        $this->assertAccountMoney($account, 450000);
        $this->assertDatabaseCount('lottery_purchases', 1);
        $this->assertDatabaseCount('lottery_tickets', 1);
        $this->assertDatabaseCount('manual_transactions', 1);
    }

    public function test_unexpected_purchase_failure_preserves_the_request_without_claiming_no_money_moved(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        config()->set('services.pw.alliance_id', 777);
        app(AllianceMembershipService::class)->clear();
        $drawing = app(LotteryService::class)->currentDrawing();
        $idempotencyKey = (string) Str::uuid();
        $lotteryService = Mockery::mock(LotteryService::class);
        $lotteryService->shouldReceive('purchaseTickets')
            ->once()
            ->andThrow(new RuntimeException('Unexpected response failure.'));
        $this->app->instance(LotteryService::class, $lotteryService);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                EnsureUserIsVerified::class,
                DiscordVerifiedMiddleware::class,
                EnsureMfaConfigured::class,
            ])
            ->post(route('lottery.tickets.store'), [
                'idempotency_key' => $idempotencyKey,
                'drawing_id' => $drawing->id,
                'account_id' => $account->id,
                'quantity' => 1,
            ]);

        $response->assertRedirect()
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHasInput('idempotency_key', $idempotencyKey)
            ->assertSessionHasErrors();
        $this->assertStringNotContainsString(
            'No money was moved',
            session('errors')->first(),
        );
    }

    public function test_purchase_rolls_back_when_the_audit_write_fails(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$user, $account] = $this->createParticipant(500000);
        $eligibilityService = Mockery::mock(AllianceMemberEligibilityService::class);
        $eligibilityService->shouldReceive('nationFor')->once()->with($user)->andReturn($user->nation);
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        $service = new LotteryService($eligibilityService, $auditLogger, new LotteryRandomizer);

        try {
            $this->purchaseTickets($service, $user, $account, 1);
            $this->fail('The purchase completed without its audit record.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable.', $exception->getMessage());
        }

        $this->assertAccountMoney($account, 500000);
        $this->assertDatabaseCount('lottery_purchases', 0);
        $this->assertDatabaseCount('lottery_tickets', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
        $this->assertSame(0, LotteryDrawing::query()->sole()->ticket_count);
    }

    public function test_draw_rolls_back_when_the_audit_write_fails(): void
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
        $eligibilityService = Mockery::mock(AllianceMemberEligibilityService::class);
        $eligibilityService->shouldNotReceive('nationFor');
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        $service = new LotteryService($eligibilityService, $auditLogger, $randomizer);

        try {
            $service->draw($drawing, CarbonImmutable::now());
            $this->fail('The drawing completed without its audit record.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable.', $exception->getMessage());
        }

        $drawing->refresh();
        $this->assertSame(LotteryDrawing::STATUS_OPEN, $drawing->status);
        $this->assertNull($drawing->winning_code);
        $this->assertNull($drawing->winning_ticket_id);
        $this->assertAccountMoney($account, 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_expired_drawing_pays_the_matching_ticket_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-07-06 12:00:00 UTC');
        [$firstUser, $firstAccount] = $this->createParticipant(500000);
        [$secondUser, $secondAccount] = $this->createParticipant(500000);
        $randomizer = Mockery::mock(LotteryRandomizer::class)->makePartial();
        $service = $this->lotteryService($randomizer, membershipChecks: 2, auditEvents: 3);

        $this->purchaseTickets($service, $firstUser, $firstAccount, 1);
        $winningTicket = $this->purchaseTickets($service, $secondUser, $secondAccount, 2)->last();
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
        $purchasedTickets = $this->purchaseTickets($service, $user, $account, 2);
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

        $nextTicket = $this->purchaseTickets($service, $user, $account, 1)->sole();

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

        $ticket = $this->purchaseTickets($service, $user, $account, 1)->sole();

        $this->assertSame($expectedFinalCode, $ticket->code);
        $this->assertSame(LotteryRandomizer::CODE_SPACE_SIZE, $drawing->refresh()->ticket_count);
        $this->assertSame(LotteryRandomizer::CODE_SPACE_SIZE, $drawing->next_ticket_sequence);
        $this->assertAccountMoney($account, 50000);

        try {
            $this->purchaseTickets($service, $user, $account, 1);
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
        $this->purchaseTickets($service, $user, $account, 1);

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
        $auditExpectation = $auditLogger->shouldReceive('record');

        if ($auditEvents > 0) {
            $auditExpectation->times($auditEvents);
        } else {
            $auditExpectation->never();
        }

        return new LotteryService($eligibilityService, $auditLogger, $randomizer);
    }

    /** @return EloquentCollection<int, LotteryTicket> */
    private function purchaseTickets(
        LotteryService $service,
        User $user,
        Account $account,
        int $quantity,
        ?string $ipAddress = null,
        ?LotteryDrawing $drawing = null,
        ?string $idempotencyKey = null,
    ): EloquentCollection {
        return $service->purchaseTickets(
            $user,
            $account,
            $drawing ?? $service->currentDrawing(),
            $quantity,
            $idempotencyKey ?? (string) Str::uuid(),
            $ipAddress,
        );
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
                    'price_paid' => SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS / 100,
                    'jackpot_contribution' => LotteryService::jackpotContributionCents(
                        SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS,
                        SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS,
                    ) / 100,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
            });

        $drawing->update([
            'ticket_count' => $quantity,
            'next_ticket_sequence' => $quantity,
            'jackpot_amount' => (LotteryService::jackpotContributionCents(
                SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS,
                SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS,
            ) / 100) * $quantity,
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
