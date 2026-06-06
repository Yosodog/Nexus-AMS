<?php

namespace Tests\Unit\Services;

use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\Nation;
use App\Services\DirectDepositService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function createTenPercentBracket(): DirectDepositTaxBracket
    {
        return DirectDepositTaxBracket::query()->create([
            'city_number' => 0,
            ...array_fill_keys(DirectDepositTaxBracket::rateFields(), 10),
        ]);
    }

    private function bankRecord(Nation $nation, int $id): BankRecord
    {
        $record = new BankRecord;
        $record->buildWithJSON((object) [
            'id' => $id,
            'date' => now()->toISOString(),
            'sender_id' => $nation->id,
            'sender_type' => 1,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'banker_id' => 1,
            'note' => 'Direct deposit test',
            'tax_id' => 555,
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
