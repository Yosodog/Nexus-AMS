<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoWithdrawSetting;
use App\Models\Nation;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoWithdrawBatchingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_batches_blockade_and_relationship_lookups_across_nations(): void
    {
        SettingService::setAutoWithdrawEnabled(true);

        foreach (range(1, 3) as $offset) {
            $nation = Nation::factory()->create(['id' => 830000 + $offset]);
            $nation->resources()->create([
                ...array_fill_keys(PWHelperService::resources(includeCredits: true), 0),
                'coal' => 100,
            ]);

            $account = new Account;
            $account->nation_id = $nation->id;
            $account->name = 'Auto Withdraw';
            $account->coal = 100;
            $account->save();

            AutoWithdrawSetting::query()->create([
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'resource' => 'coal',
                'threshold' => 50,
                'withdraw_amount' => 10,
                'enabled' => true,
            ]);
        }

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'wars' => [
                        'data' => [],
                        'paginatorInfo' => [
                            'perPage' => 1000,
                            'count' => 0,
                            'lastPage' => 1,
                        ],
                    ],
                ],
            ]),
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->assertSame(0, Artisan::call('auto:withdraw'));

        Http::assertSentCount(1);
        $this->assertSame(2, $this->queryCountContaining($queries, 'auto_withdraw_settings'));
        $this->assertSame(1, $this->queryCountContaining($queries, 'nation_resources'));
        $this->assertSame(1, $this->queryCountSelectingFrom($queries, 'accounts'));
        $this->assertSame(1, $this->queryCountSelectingFrom($queries, 'settings'));
    }

    /** @param array<int, string> $queries */
    private function queryCountContaining(array $queries, string $needle): int
    {
        return collect($queries)->filter(fn (string $query): bool => str_contains($query, $needle))->count();
    }

    /** @param array<int, string> $queries */
    private function queryCountSelectingFrom(array $queries, string $table): int
    {
        $pattern = '/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i';

        return collect($queries)->filter(fn (string $query): bool => preg_match($pattern, $query) === 1)->count();
    }
}
