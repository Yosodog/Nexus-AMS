<?php

namespace Tests\Feature;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Livewire\CalculatorCenter;
use App\Models\City;
use App\Models\Nation;
use App\Models\User;
use App\Services\Economy\EconomyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalculatorCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculator_center_requires_authentication(): void
    {
        $this->get(route('calculators.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_member_can_view_the_calculator_hub(): void
    {
        [$user] = $this->memberWithCity();

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('calculators.index'))
            ->assertOk()
            ->assertSeeLivewire(CalculatorCenter::class)
            ->assertSeeText('Calculator center')
            ->assertSeeText('City purchase cost')
            ->assertSeeText('Infrastructure purchase cost')
            ->assertSeeText('Land purchase cost')
            ->assertSeeText('Project purchase cost')
            ->assertSeeText('Military research cost')
            ->assertSeeText('Military unit purchase and upkeep')
            ->assertSeeText('City and build economics');
    }

    public function test_city_calculator_action(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('city.city_number', 20)
            ->set('city.top_twenty_average', 40.8216)
            ->set('city.manifest_destiny', false)
            ->call('calculateCity')
            ->assertHasNoErrors()
            ->assertSet('cityResult.calculator', 'city_purchase')
            ->assertSet('cityResult.breakdowns.purchase.money', 95_507_890.91);
    }

    public function test_infrastructure_calculator_action_and_invalid_state(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('infrastructure.current', 55)
            ->set('infrastructure.target', 300)
            ->call('calculateInfrastructure')
            ->assertHasNoErrors()
            ->assertSet('infrastructureResult.calculator', 'infrastructure_purchase')
            ->assertSet('infrastructureResult.breakdowns.purchase.money', 91_101.95)
            ->set('infrastructure.target', 54)
            ->call('calculateInfrastructure')
            ->assertHasErrors('infrastructure.form')
            ->assertSet('infrastructureResult', null);
    }

    public function test_land_calculator_action(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('land.current', 250)
            ->set('land.target', 1_000)
            ->call('calculateLand')
            ->assertHasNoErrors()
            ->assertSet('landResult.calculator', 'land_purchase')
            ->assertSet('landResult.breakdowns.purchase.money', 356_850.0);
    }

    public function test_project_calculator_action(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('project.project', 'arms_stockpile')
            ->set('project.technological_advancement', true)
            ->set('project.government_support_agency', true)
            ->set('project.bureau_of_domestic_affairs', true)
            ->call('calculateProject')
            ->assertHasNoErrors()
            ->assertSet('projectResult.calculator', 'project_purchase')
            ->assertSet('projectResult.breakdowns.purchase.money', 9_125_000.0)
            ->assertSet('projectResult.breakdowns.purchase.resources.coal', 456.25)
            ->assertSeeText('Arms Stockpile')
            ->assertDontSeeText('Catalog source commit');
    }

    public function test_research_calculator_action(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('research.target.ground_cost', 1)
            ->call('calculateResearch')
            ->assertHasNoErrors()
            ->assertSet('researchResult.calculator', 'military_research_purchase')
            ->assertSet('researchResult.breakdowns.purchase.money', 602_250.0)
            ->assertSet('researchResult.breakdowns.purchase.resources.food', 10_000.0)
            ->set('research.target.ground_cost', 21)
            ->call('calculateResearch')
            ->assertHasErrors('research.target.ground_cost')
            ->assertSet('researchResult', null);
    }

    public function test_military_unit_calculator_action(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('military.quantities.aircraft', 2)
            ->call('calculateMilitary')
            ->assertHasNoErrors()
            ->assertSet('militaryResult.calculator', 'military_unit_cost')
            ->assertSet('militaryResult.breakdowns.purchase.money', 8_000.0)
            ->assertSet('militaryResult.breakdowns.purchase.resources.aluminum', 20.0)
            ->assertSet('militaryResult.breakdowns.daily_upkeep.money', 1_500.0);
    }

    public function test_city_economics_calculator_action(): void
    {
        [$user] = $this->memberWithCity();

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->set('economics.buildings.wind_power', 4)
            ->call('calculateEconomics')
            ->assertHasNoErrors()
            ->assertSet('economicsResult.calculator', 'city_build_economics')
            ->assertSet('economicsResult.metrics.powered', true)
            ->assertSeeText('City powered')
            ->assertDontSeeText('Model version');
    }

    public function test_prefill_is_scoped_to_the_authenticated_users_nation(): void
    {
        [$user, $ownCity] = $this->memberWithCity();
        $otherNation = Nation::factory()->create();
        $otherCity = $this->createCity($otherNation, ['infrastructure' => 2_500.0]);

        Livewire::actingAs($user)
            ->test(CalculatorCenter::class)
            ->call('prefillCity', $otherCity->id)
            ->assertHasErrors('prefill')
            ->call('prefillCity', $ownCity->id)
            ->assertHasNoErrors('prefill')
            ->assertSet('selectedCityId', $ownCity->id)
            ->assertSet('economics.infrastructure', 1_000.0);
    }

    /**
     * @return array{0: User, 1: City}
     */
    private function memberWithCity(): array
    {
        $nation = Nation::factory()->create([
            'continent' => 'NA',
            'num_cities' => 10,
            'domestic_policy' => 'MANIFEST_DESTINY',
            'project_bits' => '0',
            'economy_context_synced_at' => now()->subHour(),
        ]);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        $city = $this->createCity($nation);

        return [$user, $city];
    }

    private function createCity(Nation $nation, array $overrides = []): City
    {
        return City::query()->create(array_replace([
            'nation_id' => $nation->id,
            'name' => 'Test City',
            'date' => now()->subYear()->toDateString(),
            'infrastructure' => 1_000.0,
            'land' => 1_000.0,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
        ], $overrides));
    }

    /**
     * @return array<int, class-string>
     */
    private function optionalIdentityMiddleware(): array
    {
        return [
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ];
    }
}
