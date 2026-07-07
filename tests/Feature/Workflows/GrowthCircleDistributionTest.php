<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Models\User;
use App\Services\GrowthCircleService;
use App\Services\NationProfitabilityService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class GrowthCircleDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_distribution_credits_food_uranium_and_raw_resource_shortfalls_once(): void
    {
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        SettingService::setGrowthCirclesTaxId(555);

        $nation = Nation::factory()->create([
            'id' => 777001,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
            'tax_id' => 555,
            'vacation_mode_turns' => 0,
            'color' => 'green',
            'num_cities' => 5,
        ]);

        User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Growth Circles';
        $account->save();

        GrowthCircleEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => 321,
            'enrolled_at' => now(),
        ]);

        $shortfalls = [
            'coal' => 12.5,
            'oil' => 4.25,
            'uranium' => 7.0,
            'iron' => 8.75,
            'bauxite' => 2.0,
            'lead' => 1.5,
            'food' => 9.0,
        ];

        $profitability = Mockery::mock(NationProfitabilityService::class);
        $profitability->shouldReceive('getDailyGrowthCircleShortfalls')
            ->once()
            ->with(Mockery::on(fn (Nation $distributionNation): bool => (int) $distributionNation->id === (int) $nation->id))
            ->andReturn($shortfalls);
        $this->app->instance(NationProfitabilityService::class, $profitability);

        app(GrowthCircleService::class)->runDailyDistribution('2026-07-06');

        $account->refresh();

        $expected = $shortfalls + ['money' => 0.0];
        foreach (GrowthCircleDistribution::distributionResourceKeys() as $resource) {
            $this->assertSame($expected[$resource] ?? 0.0, (float) $account->{$resource}, $resource.' was not credited correctly.');
        }

        $this->assertSame(0.0, (float) $account->gasoline);
        $this->assertSame(0.0, (float) $account->munitions);
        $this->assertSame(0.0, (float) $account->steel);
        $this->assertSame(0.0, (float) $account->aluminum);

        $this->assertDatabaseHas('growth_circle_distributions', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'cycle_date' => '2026-07-06',
            'coal' => 12.5,
            'oil' => 4.25,
            'uranium' => 7.0,
            'iron' => 8.75,
            'bauxite' => 2.0,
            'lead' => 1.5,
            'food' => 9.0,
        ]);

        $this->assertDatabaseHas('alliance_finance_entries', [
            'category' => 'growth_circles_distribution',
            'direction' => 'expense',
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'coal' => 12.5,
            'oil' => 4.25,
            'uranium' => 7.0,
            'iron' => 8.75,
            'bauxite' => 2.0,
            'lead' => 1.5,
            'food' => 9.0,
        ]);
    }
}
