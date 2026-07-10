<?php

namespace Tests\Feature\Workflows;

use App\Http\Requests\SellMarketRequest;
use App\Models\Account;
use App\Models\MarketResource;
use App\Models\Nation;
use App\Models\TradePrice;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\MarketService;
use App\Services\TradePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketSaleWorkflowTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_three_decimal_amount_is_rejected_at_request_and_service_boundaries(): void
    {
        [$user, $nation, $account] = $this->createMarketParticipant();
        $marketResource = $this->createMarketResource();

        $validator = Validator::make([
            'account_id' => $account->id,
            'resource' => 'coal',
            'amount' => '1.001',
        ], (new SellMarketRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('amount', $validator->errors()->toArray());

        $service = $this->marketService($nation, 100, expectPriceLookup: false);

        try {
            $service->sell($user, $account, 'coal', 1.001);
            $this->fail('The service accepted a market amount with more than two decimal places.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Amount may not contain more than 2 decimal places.'],
                $exception->errors()['amount']
            );
        }

        $this->assertBalancesAndCap($account, $marketResource, money: 20, coal: 10, cap: 100);
        $this->assertDatabaseCount('market_transactions', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    #[DataProvider('unavailablePriceProvider')]
    public function test_missing_or_zero_price_is_rejected_without_balance_changes(?float $basePrice): void
    {
        [$user, $nation, $account] = $this->createMarketParticipant();
        $marketResource = $this->createMarketResource();
        $service = $this->marketService($nation, $basePrice);

        try {
            $service->sell($user, $account, 'coal', 2.50);
            $this->fail('The service accepted a sale without a positive base price.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['No current market price is available for this resource.'],
                $exception->errors()['resource']
            );
        }

        $this->assertBalancesAndCap($account, $marketResource, money: 20, coal: 10, cap: 100);
        $this->assertDatabaseCount('market_transactions', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_valid_sale_uses_canonical_decimal_quantities_and_payout(): void
    {
        [$user, $nation, $account] = $this->createMarketParticipant();
        $marketResource = $this->createMarketResource(adjustmentPercent: 10);
        $service = $this->marketService($nation, 100, expectAudit: true);

        $transaction = $service->sell($user, $account, 'coal', 2.50);

        $this->assertBalancesAndCap($account, $marketResource, money: 295, coal: 7.50, cap: 97.50);
        $this->assertSame('2.50', $transaction->amount);
        $this->assertSame('110.0000', $transaction->final_price);
        $this->assertSame('275.00', $transaction->money_paid);
        $this->assertDatabaseCount('market_transactions', 1);
        $this->assertDatabaseCount('manual_transactions', 1);
    }

    public function test_sale_with_zero_cent_payout_is_rejected_without_balance_changes(): void
    {
        [$user, $nation, $account] = $this->createMarketParticipant();
        $marketResource = $this->createMarketResource(adjustmentPercent: MarketResource::MIN_ADJUSTMENT_PERCENT);
        $service = $this->marketService($nation, 1);

        try {
            $service->sell($user, $account, 'coal', 1);
            $this->fail('The service accepted a sale whose payout rounds to zero cents.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This sale is too small to produce a positive payout.'],
                $exception->errors()['amount']
            );
        }

        $this->assertBalancesAndCap($account, $marketResource, money: 20, coal: 10, cap: 100);
        $this->assertDatabaseCount('market_transactions', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    /**
     * @return array<string, array{0: float|null}>
     */
    public static function unavailablePriceProvider(): array
    {
        return [
            'missing price' => [null],
            'zero price' => [0.0],
        ];
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMarketParticipant(): array
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
        ]);
        $user = User::factory()->create(['nation_id' => $nation->id]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Market Account';
        $account->money = 20;
        $account->coal = 10;
        $account->save();

        return [$user, $nation, $account];
    }

    private function createMarketResource(float $adjustmentPercent = 0): MarketResource
    {
        return MarketResource::query()->create([
            'resource' => 'coal',
            'is_enabled' => true,
            'adjustment_percent' => $adjustmentPercent,
            'buy_cap_remaining' => 100,
        ]);
    }

    private function marketService(
        Nation $nation,
        ?float $basePrice,
        bool $expectPriceLookup = true,
        bool $expectAudit = false
    ): MarketService {
        $tradePriceService = Mockery::mock(TradePriceService::class);

        if ($expectPriceLookup) {
            $average = new TradePrice;

            if ($basePrice !== null) {
                $average->coal = $basePrice;
            }

            $tradePriceService->shouldReceive('get24hAverage')->once()->andReturn($average);
        } else {
            $tradePriceService->shouldNotReceive('get24hAverage');
        }

        $membershipService = Mockery::mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('contains')->once()->with($nation->alliance_id)->andReturnTrue();

        $auditLogger = Mockery::mock(AuditLogger::class);

        if ($expectAudit) {
            $auditLogger->shouldReceive('recordAfterCommit')->once();
        } else {
            $auditLogger->shouldNotReceive('recordAfterCommit');
        }

        return new MarketService($tradePriceService, $membershipService, $auditLogger);
    }

    private function assertBalancesAndCap(
        Account $account,
        MarketResource $marketResource,
        float $money,
        float $coal,
        float $cap
    ): void {
        $account->refresh();
        $marketResource->refresh();

        $this->assertSame(number_format($money, 2, '.', ''), number_format((float) $account->money, 2, '.', ''));
        $this->assertSame(number_format($coal, 2, '.', ''), number_format((float) $account->coal, 2, '.', ''));
        $this->assertSame(number_format($cap, 2, '.', ''), number_format((float) $marketResource->buy_cap_remaining, 2, '.', ''));
    }
}
