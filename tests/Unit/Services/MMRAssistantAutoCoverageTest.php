<?php

namespace Tests\Unit\Services;

use App\Events\AllianceExpenseOccurred;
use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRConfig;
use App\Models\MMRSetting;
use App\Models\Nation;
use App\Models\NationProfitabilitySnapshot;
use App\Services\DirectDepositService;
use App\Services\Economy\EconomyRules;
use App\Services\MMRAssistantService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\TradePriceService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MMRAssistantAutoCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::setMMRAssistantEnabled(true);
    }

    public function test_automatic_plan_covers_one_turn_of_each_projected_deficit(): void
    {
        Event::fake([AllianceExpenseOccurred::class]);
        [$nation, $account] = $this->createConfiguredNation();
        $this->enableResource('coal');
        $this->enableResource('oil');
        $projectionTime = now()->subHour()->startOfSecond();
        $this->createProjection($nation, [
            'coal' => -384,
            'oil' => -120,
            'food' => 50,
        ], $projectionTime);
        $this->mockPrices(['coal' => 10, 'oil' => 20]);

        $plan = app(MMRAssistantService::class)->plan($nation, 1000);

        $this->assertSame(MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC, $plan['mode']);
        $this->assertSame('available', $plan['status']);
        $this->assertSame(520.0, $plan['target_spend']);
        $this->assertSame(520.0, $plan['total_spend']);
        $this->assertSame(100.0, $plan['coverage_pct']);
        $this->assertSame(32.0, $plan['lines']['coal']['target_qty']);
        $this->assertSame(32.0, $plan['lines']['coal']['qty']);
        $this->assertSame(10.0, $plan['lines']['oil']['target_qty']);
        $this->assertSame(10.0, $plan['lines']['oil']['qty']);

        $purchase = app(MMRAssistantService::class)->applyPlan($account, $plan);

        $this->assertNotNull($purchase);
        $this->assertSame(MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC, $purchase->allocation_mode);
        $this->assertTrue($projectionTime->equalTo($purchase->projection_calculated_at));
        $this->assertSame(32.0, (float) $account->fresh()->coal);
        $this->assertSame(10.0, (float) $account->fresh()->oil);
        Event::assertDispatched(AllianceExpenseOccurred::class);
    }

    public function test_direct_deposit_withholds_the_automatic_plan_and_credits_resources(): void
    {
        Event::fake();
        SettingService::setDirectDepositId(555);
        DirectDepositTaxBracket::query()->create([
            'city_number' => 0,
            ...array_fill_keys(DirectDepositTaxBracket::rateFields(), 10),
        ]);
        [$nation, $account] = $this->createConfiguredNation();
        $this->enableResource('coal');
        $this->createProjection($nation, ['coal' => -384]);
        $this->mockPrices(['coal' => 10]);

        $taxRecord = app(DirectDepositService::class)->process($this->bankRecord($nation));

        $this->assertSame(100.0, (float) $taxRecord->money);
        $this->assertSame(580.0, (float) $account->fresh()->money);
        $this->assertSame(32.0, (float) $account->fresh()->coal);
        $this->assertSame(900.0, (float) DirectDepositLog::query()->sole()->money);
        $this->assertDatabaseHas('mmr_assistant_purchases', [
            'account_id' => $account->id,
            'total_spent' => 320,
            'allocation_mode' => MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC,
            'coal' => 32,
            'coal_ppu' => 10,
        ]);
    }

    public function test_automatic_plan_scales_every_deficit_when_cash_is_insufficient(): void
    {
        [$nation] = $this->createConfiguredNation();
        $this->enableResource('coal');
        $this->enableResource('oil');
        $this->createProjection($nation, [
            'coal' => -384,
            'oil' => -120,
        ]);
        $this->mockPrices(['coal' => 10, 'oil' => 20]);

        $plan = app(MMRAssistantService::class)->plan($nation, 260);

        $this->assertSame(260.0, $plan['total_spend']);
        $this->assertSame(50.0, $plan['coverage_pct']);
        $this->assertSame(16.0, $plan['lines']['coal']['qty']);
        $this->assertSame(5.0, $plan['lines']['oil']['qty']);
        $this->assertSame(50.0, $plan['lines']['coal']['coverage_pct']);
        $this->assertSame(50.0, $plan['lines']['oil']['coverage_pct']);
    }

    public function test_automatic_plan_can_fully_cover_the_smallest_supported_quantity(): void
    {
        [$nation] = $this->createConfiguredNation();
        $this->enableResource('coal');
        $this->createProjection($nation, ['coal' => -0.12]);
        $this->mockPrices(['coal' => 333.33]);

        $plan = app(MMRAssistantService::class)->plan($nation, 3.33);

        $this->assertSame(0.01, $plan['lines']['coal']['target_qty']);
        $this->assertSame(0.01, $plan['lines']['coal']['qty']);
        $this->assertSame(3.33, $plan['total_spend']);
        $this->assertSame(100.0, $plan['coverage_pct']);
    }

    public function test_automatic_plan_uses_pooled_rounding_cash_for_an_affordable_minimum_unit(): void
    {
        [$nation] = $this->createConfiguredNation();
        $this->enableResource('coal');
        $this->enableResource('oil');
        $this->createProjection($nation, [
            'coal' => -0.12,
            'oil' => -0.12,
        ]);
        $this->mockPrices(['coal' => 333.33, 'oil' => 333.33]);

        $plan = app(MMRAssistantService::class)->plan($nation, 3.33);

        $this->assertSame(3.33, $plan['total_spend']);
        $this->assertSame(50.0, $plan['coverage_pct']);
        $this->assertSame(0.01, $plan['lines']['coal']['qty']);
        $this->assertSame(0.0, $plan['lines']['oil']['qty']);
    }

    public function test_automatic_plan_leaves_cash_untouched_when_projection_is_stale(): void
    {
        [$nation] = $this->createConfiguredNation();
        $this->enableResource('coal');
        $this->createProjection($nation, ['coal' => -384], now()->subHours(4));
        $this->mockPrices(['coal' => 10]);

        $plan = app(MMRAssistantService::class)->plan($nation, 1000);

        $this->assertSame('projection_unavailable', $plan['status']);
        $this->assertSame(0.0, $plan['total_spend']);
        $this->assertSame([], $plan['lines']);
        $this->assertNotNull($plan['account']);
    }

    public function test_automatic_plan_reports_deficits_for_disabled_resources_without_buying_them(): void
    {
        [$nation] = $this->createConfiguredNation();
        $this->enableResource('coal', enabled: false);
        $this->createProjection($nation, ['coal' => -384]);
        $this->mockPrices(['coal' => 10]);

        $plan = app(MMRAssistantService::class)->plan($nation, 1000);

        $this->assertSame('no_purchasable_deficits', $plan['status']);
        $this->assertSame(['coal'], $plan['unavailable_resources']);
        $this->assertSame(32.0, $plan['lines']['coal']['target_qty']);
        $this->assertSame(0.0, $plan['lines']['coal']['qty']);
        $this->assertSame(0.0, $plan['total_spend']);
    }

    public function test_manual_plan_behavior_is_unchanged_when_automatic_coverage_is_off(): void
    {
        [$nation] = $this->createConfiguredNation([
            'auto_cover_resource_deficits' => false,
            'coal_pct' => 25,
        ]);
        $this->enableResource('coal');
        $this->mockPrices(['coal' => 10]);

        $plan = app(MMRAssistantService::class)->plan($nation, 1000);

        $this->assertSame(MMRAssistantPurchase::ALLOCATION_MODE_MANUAL, $plan['mode']);
        $this->assertSame('manual', $plan['status']);
        $this->assertSame(250.0, $plan['total_spend']);
        $this->assertSame(25.0, $plan['lines']['coal']['qty']);
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     * @return array{Nation, Account}
     */
    private function createConfiguredNation(array $configOverrides = []): array
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'MMR Resources';
        $account->save();

        MMRConfig::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'enabled' => true,
            'auto_cover_resource_deficits' => true,
            ...$configOverrides,
        ]);

        return [$nation, $account];
    }

    private function enableResource(string $resource, bool $enabled = true): void
    {
        MMRSetting::query()->create([
            'resource' => $resource,
            'enabled' => $enabled,
            'surcharge_pct' => 0,
        ]);
    }

    /**
     * @param  array<string, float|int>  $resources
     */
    private function createProjection(
        Nation $nation,
        array $resources,
        ?CarbonInterface $calculatedAt = null,
    ): void {
        NationProfitabilitySnapshot::query()->create([
            'nation_id' => $nation->id,
            'alliance_id' => $nation->alliance_id,
            'model_version' => EconomyRules::MODEL_VERSION,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'cities' => $nation->num_cities,
            'resource_profit_per_day' => [
                ...array_fill_keys(EconomyRules::RESOURCE_KEYS, 0.0),
                ...$resources,
            ],
            'calculated_at' => $calculatedAt ?? now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, float|int>  $prices
     */
    private function mockPrices(array $prices): void
    {
        $priceService = $this->createMock(TradePriceService::class);
        $priceService->method('get24hAverageWithSurcharge')->willReturn($prices);
        $this->app->instance(TradePriceService::class, $priceService);
    }

    private function bankRecord(Nation $nation): BankRecord
    {
        $record = new BankRecord;
        $record->buildWithJSON((object) [
            'id' => 987654,
            'date' => now()->toISOString(),
            'sender_id' => $nation->id,
            'sender_type' => 1,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'banker_id' => 1,
            'note' => 'Automatic MMR coverage test',
            'tax_id' => 555,
            ...collect(PWHelperService::resources())
                ->mapWithKeys(fn (string $resource): array => [
                    $resource => $resource === 'money' ? 1000 : 0,
                ])
                ->all(),
        ]);

        return $record;
    }
}
