<?php

namespace Tests\Unit\Services;

use App\Events\AllianceExpenseOccurred;
use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\Alliance;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRConfig;
use App\Models\MMRSetting;
use App\Models\Nation;
use App\Models\Offshore;
use App\Services\DirectDepositService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\TradePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class DirectDepositIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_returns_retained_taxes_without_crediting_member_twice(): void
    {
        SettingService::setDirectDepositId(555);
        $this->createTenPercentBracket();

        $nation = Nation::factory()->create(['num_cities' => 5]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Direct Deposit';
        $account->save();

        $first = app(DirectDepositService::class)->process($this->bankRecord($nation, 12345));

        $this->assertSame(100.0, (float) $first->money);
        $this->assertSame(10.0, (float) $first->coal);
        $this->assertSame('900.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('90.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertDatabaseHas('direct_deposit_logs', [
            'bank_record_id' => 12345,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'money' => 900,
            'coal' => 90,
        ]);

        $retry = app(DirectDepositService::class)->process($this->bankRecord($nation, 12345));

        $this->assertSame(100.0, (float) $retry->money);
        $this->assertSame(10.0, (float) $retry->coal);
        $this->assertSame('900.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('90.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertSame(1, DirectDepositLog::query()->where('bank_record_id', 12345)->count());
    }

    public function test_mmr_failure_rolls_back_and_retry_applies_purchase_exactly_once(): void
    {
        $apiTimestamp = '2026-08-02T05:06:07+00:00';
        SettingService::setDirectDepositId(555);
        SettingService::setMMRAssistantEnabled(true);
        $this->createTenPercentBracket();

        $nation = Nation::factory()->create(['num_cities' => 5]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Direct Deposit';
        $account->save();

        MMRConfig::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'enabled' => true,
            'coal_pct' => 20,
        ]);
        MMRSetting::query()->create([
            'resource' => 'coal',
            'enabled' => true,
            'surcharge_pct' => 0,
        ]);

        $tradePrices = $this->createMock(TradePriceService::class);
        $tradePrices->method('get24hAverageWithSurcharge')->willReturn(['coal' => 18.0]);
        $this->app->instance(TradePriceService::class, $tradePrices);

        $failFirstExpenseEvent = true;
        Event::listen(AllianceExpenseOccurred::class, function () use (&$failFirstExpenseEvent): void {
            if ($failFirstExpenseEvent) {
                $failFirstExpenseEvent = false;

                throw new RuntimeException('Injected MMR expense failure.');
            }
        });

        try {
            app(DirectDepositService::class)->process($this->bankRecord($nation, 54321, $apiTimestamp));
            $this->fail('The injected MMR failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected MMR expense failure.', $exception->getMessage());
        }

        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertSame(0, DirectDepositLog::query()->where('bank_record_id', 54321)->count());
        $this->assertSame(0, MMRAssistantPurchase::query()->count());

        $retry = app(DirectDepositService::class)->process($this->bankRecord($nation, 54321, $apiTimestamp));

        $this->assertSame(100.0, (float) $retry->money);
        $this->assertSame(10.0, (float) $retry->coal);
        $this->assertSame('720.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertSame(1, DirectDepositLog::query()->where('bank_record_id', 54321)->count());
        $this->assertDatabaseHas('direct_deposit_logs', [
            'bank_record_id' => 54321,
            'money' => 900,
            'coal' => 90,
        ]);
        $this->assertDatabaseHas('mmr_assistant_purchases', [
            'account_id' => $account->id,
            'total_spent' => 180,
            'coal' => 10,
            'coal_ppu' => 18,
        ]);
        $this->assertSame(
            $apiTimestamp,
            DirectDepositLog::query()->where('bank_record_id', 54321)->firstOrFail()->created_at->toAtomString(),
        );
        $this->assertSame(
            $apiTimestamp,
            DirectDepositLog::query()->where('bank_record_id', 54321)->firstOrFail()->updated_at->toAtomString(),
        );
        $this->assertSame(
            $apiTimestamp,
            MMRAssistantPurchase::query()->firstOrFail()->created_at->toAtomString(),
        );
        $this->assertSame(
            $apiTimestamp,
            MMRAssistantPurchase::query()->firstOrFail()->updated_at->toAtomString(),
        );

        app(DirectDepositService::class)->process($this->bankRecord($nation, 54321, $apiTimestamp));

        $this->assertSame('720.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertSame(1, MMRAssistantPurchase::query()->count());
    }

    public function test_offshore_tax_record_uses_the_receiving_alliance_direct_deposit_tax_id(): void
    {
        config()->set('services.pw.alliance_id', 777);
        SettingService::setDirectDepositId(555);
        $this->createTenPercentBracket();

        Alliance::factory()->create(['id' => 888]);
        Offshore::query()->create([
            'name' => 'Direct Deposit Offshore',
            'alliance_id' => 888,
            'enabled' => true,
            'direct_deposit_tax_id' => 777,
            'direct_deposit_fallback_tax_id' => 778,
        ]);

        $nation = Nation::factory()->create([
            'alliance_id' => 888,
            'num_cities' => 5,
        ]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Offshore Direct Deposit';
        $account->save();

        $taxRecord = app(DirectDepositService::class)->process(
            $this->bankRecord($nation, 65432, receiverId: 888, taxId: 777),
        );

        $this->assertSame(100.0, (float) $taxRecord->money);
        $this->assertSame(10.0, (float) $taxRecord->coal);
        $this->assertSame(900.0, (float) $account->fresh()->money);
        $this->assertSame(90.0, (float) $account->fresh()->coal);
    }

    private function createTenPercentBracket(): DirectDepositTaxBracket
    {
        return DirectDepositTaxBracket::query()->create([
            'city_number' => 0,
            ...array_fill_keys(DirectDepositTaxBracket::rateFields(), 10),
        ]);
    }

    private function bankRecord(
        Nation $nation,
        int $id,
        ?string $date = null,
        int $receiverId = 777,
        int $taxId = 555,
    ): BankRecord {
        $record = new BankRecord;
        $record->buildWithJSON((object) [
            'id' => $id,
            'date' => $date ?? now()->toISOString(),
            'sender_id' => $nation->id,
            'sender_type' => 1,
            'receiver_id' => $receiverId,
            'receiver_type' => 2,
            'banker_id' => 1,
            'note' => 'Direct deposit test',
            'tax_id' => $taxId,
            ...$this->resourcePayload([
                'money' => 1000,
                'coal' => 100,
            ]),
        ]);

        return $record;
    }

    /**
     * @param  array<string, float|int>  $overrides
     * @return array<string, float|int>
     */
    private function resourcePayload(array $overrides = []): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => $overrides[$resource] ?? 0])
            ->all();
    }
}
