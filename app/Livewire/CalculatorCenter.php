<?php

namespace App\Livewire;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Services\Calculators\CityEconomicsCalculator;
use App\Services\Calculators\GamePurchaseCostCalculator;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\Calculators\ProjectCostCatalog;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketValuationService;
use App\Services\RadiationService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CalculatorCenter extends Component
{
    /** @var array<string, mixed> */
    public array $city = [
        'city_number' => 2,
        'top_twenty_average' => null,
        'manifest_destiny' => false,
        'government_support_agency' => false,
        'bureau_of_domestic_affairs' => false,
    ];

    /** @var array<string, mixed> */
    public array $infrastructure = [
        'current' => 10.0,
        'target' => 1_000.0,
        'urbanization' => false,
        'center_for_civil_engineering' => false,
        'advanced_engineering_corps' => false,
        'government_support_agency' => false,
        'bureau_of_domestic_affairs' => false,
    ];

    /** @var array<string, mixed> */
    public array $land = [
        'current' => 250.0,
        'target' => 1_000.0,
        'rapid_expansion' => false,
        'arable_land_agency' => false,
        'advanced_engineering_corps' => false,
        'government_support_agency' => false,
        'bureau_of_domestic_affairs' => false,
    ];

    /** @var array<string, mixed> */
    public array $project = [
        'project' => 'arms_stockpile',
        'technological_advancement' => false,
        'government_support_agency' => false,
        'bureau_of_domestic_affairs' => false,
    ];

    /** @var array<string, mixed> */
    public array $research = [
        'current' => [
            'ground_capacity' => 0,
            'ground_cost' => 0,
            'air_capacity' => 0,
            'air_cost' => 0,
            'naval_capacity' => 0,
            'naval_cost' => 0,
        ],
        'target' => [
            'ground_capacity' => 0,
            'ground_cost' => 0,
            'air_capacity' => 0,
            'air_cost' => 0,
            'naval_capacity' => 0,
            'naval_cost' => 0,
        ],
        'military_doctrine' => false,
    ];

    /** @var array<string, mixed> */
    public array $military = [
        'quantities' => [
            'soldiers' => 0,
            'tanks' => 0,
            'aircraft' => 0,
            'ships' => 0,
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 0,
        ],
        'research' => [
            'ground_cost' => 0,
            'ground_capacity' => 0,
            'air_cost' => 0,
            'air_capacity' => 0,
            'naval_cost' => 0,
            'naval_capacity' => 0,
        ],
        'wartime' => false,
        'imperialism' => false,
        'government_support_agency' => false,
        'bureau_of_domestic_affairs' => false,
    ];

    /** @var array<string, mixed> */
    public array $economics = [
        'continent' => 'NA',
        'num_cities' => 1,
        'domestic_policy' => 'NONE',
        'treasure_income_percent' => 0.0,
        'infrastructure' => 1_000.0,
        'land' => 1_000.0,
        'age_days' => 365,
        'powered' => true,
        'roi_days' => 30,
        'buildings' => [],
        'projects' => [],
    ];

    public ?array $cityResult = null;

    public ?array $infrastructureResult = null;

    public ?array $landResult = null;

    public ?array $projectResult = null;

    public ?array $researchResult = null;

    public ?array $militaryResult = null;

    public ?array $economicsResult = null;

    public ?int $selectedCityId = null;

    #[Locked]
    public array $projectOptions = [];

    #[Locked]
    public array $nationCities = [];

    #[Locked]
    public array $buildingGroups = [];

    #[Locked]
    public array $economyProjectOptions = [];

    #[Locked]
    public array $cityAverageContext = [];

    #[Locked]
    public array $marketContext = [];

    #[Locked]
    public array $nationContext = [];

    #[Locked]
    public array $worldContext = [];

    public function mount(): void
    {
        $this->projectOptions = app(ProjectCostCatalog::class)->all();
        $this->buildingGroups = [
            'Power' => EconomyRules::POWER_FIELDS,
            'Raw resources' => array_keys(EconomyRules::RAW_BUILDINGS),
            'Manufacturing' => array_keys(EconomyRules::MANUFACTURING_BUILDINGS),
            'Commerce and services' => EconomyRules::SUPPORT_FIELDS,
            'Military improvements' => EconomyRules::MILITARY_BUILDING_FIELDS,
        ];
        $this->economyProjectOptions = CityEconomicsCalculator::ECONOMY_PROJECTS;
        $this->economics['buildings'] = array_fill_keys(EconomyRules::BUILD_FIELDS, 0);
        $this->economics['projects'] = array_fill_keys(array_keys(CityEconomicsCalculator::ECONOMY_PROJECTS), false);
        $this->loadCityAverage();
        $this->resolveMarketPrices();
        $this->loadWorldContext();
        $this->applyNationPrefill();
    }

    public function prefillFromNation(): void
    {
        $this->resetValidation();
        $this->applyNationPrefill();
    }

    public function prefillCity(int $cityId): void
    {
        $user = Auth::user();
        $city = $user?->nation?->cities()->whereKey($cityId)->first();

        if (! $city instanceof City) {
            throw ValidationException::withMessages([
                'prefill' => 'That city is not available for your nation.',
            ]);
        }

        $this->selectedCityId = $city->id;
        $this->applyCityPrefill($city);
        $this->nationContext['calculated_from'] = $this->latestTimestamp([
            $user->nation->economy_context_synced_at,
            $user->nation->updated_at,
            $city->updated_at,
        ]);
    }

    public function calculateCity(): void
    {
        $this->resetValidation();
        $this->cityResult = null;
        $validated = $this->validate([
            'city.city_number' => ['required', 'integer', 'min:1', 'max:10000'],
            'city.top_twenty_average' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'city.manifest_destiny' => ['boolean'],
            'city.government_support_agency' => ['boolean'],
            'city.bureau_of_domestic_affairs' => ['boolean'],
        ]);
        $prices = $this->resolveMarketPrices();

        $this->captureResult('cityResult', 'city.form', fn () => app(GamePurchaseCostCalculator::class)->city(
            (int) $validated['city']['city_number'],
            (float) $validated['city']['top_twenty_average'],
            (bool) $validated['city']['manifest_destiny'],
            (bool) $validated['city']['government_support_agency'],
            (bool) $validated['city']['bureau_of_domestic_affairs'],
            $prices,
        ));
    }

    public function calculateInfrastructure(): void
    {
        $this->resetValidation();
        $this->infrastructureResult = null;
        $validated = $this->validate([
            'infrastructure.current' => ['required', 'numeric', 'min:0', 'max:20000'],
            'infrastructure.target' => ['required', 'numeric', 'min:0', 'max:20000'],
            'infrastructure.urbanization' => ['boolean'],
            'infrastructure.center_for_civil_engineering' => ['boolean'],
            'infrastructure.advanced_engineering_corps' => ['boolean'],
            'infrastructure.government_support_agency' => ['boolean'],
            'infrastructure.bureau_of_domestic_affairs' => ['boolean'],
        ]);
        $form = $validated['infrastructure'];
        $prices = $this->resolveMarketPrices();

        $this->captureResult('infrastructureResult', 'infrastructure.form', fn () => app(GamePurchaseCostCalculator::class)->infrastructure(
            (float) $form['current'],
            (float) $form['target'],
            (bool) $form['urbanization'],
            (bool) $form['center_for_civil_engineering'],
            (bool) $form['advanced_engineering_corps'],
            (bool) $form['government_support_agency'],
            (bool) $form['bureau_of_domestic_affairs'],
            $prices,
        ));
    }

    public function calculateLand(): void
    {
        $this->resetValidation();
        $this->landResult = null;
        $validated = $this->validate([
            'land.current' => ['required', 'numeric', 'min:0', 'max:20000'],
            'land.target' => ['required', 'numeric', 'min:0', 'max:20000'],
            'land.rapid_expansion' => ['boolean'],
            'land.arable_land_agency' => ['boolean'],
            'land.advanced_engineering_corps' => ['boolean'],
            'land.government_support_agency' => ['boolean'],
            'land.bureau_of_domestic_affairs' => ['boolean'],
        ]);
        $form = $validated['land'];
        $prices = $this->resolveMarketPrices();

        $this->captureResult('landResult', 'land.form', fn () => app(GamePurchaseCostCalculator::class)->land(
            (float) $form['current'],
            (float) $form['target'],
            (bool) $form['rapid_expansion'],
            (bool) $form['arable_land_agency'],
            (bool) $form['advanced_engineering_corps'],
            (bool) $form['government_support_agency'],
            (bool) $form['bureau_of_domestic_affairs'],
            $prices,
        ));
    }

    public function calculateProject(): void
    {
        $this->resetValidation();
        $this->projectResult = null;
        $validated = $this->validate([
            'project.project' => ['required', 'string'],
            'project.technological_advancement' => ['boolean'],
            'project.government_support_agency' => ['boolean'],
            'project.bureau_of_domestic_affairs' => ['boolean'],
        ]);
        $form = $validated['project'];
        $prices = $this->resolveMarketPrices();

        $this->captureResult('projectResult', 'project.form', fn () => app(GamePurchaseCostCalculator::class)->project(
            $form['project'],
            (bool) $form['technological_advancement'],
            (bool) $form['government_support_agency'],
            (bool) $form['bureau_of_domestic_affairs'],
            $prices,
        ));
    }

    public function calculateResearch(): void
    {
        $this->resetValidation();
        $this->researchResult = null;
        $rules = ['research.military_doctrine' => ['boolean']];
        foreach (array_keys(GamePurchaseCostCalculator::RESEARCH_BRANCHES) as $branch) {
            $rules["research.current.{$branch}"] = ['required', 'integer', 'min:0', 'max:20'];
            $rules["research.target.{$branch}"] = ['required', 'integer', 'min:0', 'max:20'];
        }
        $validated = $this->validate($rules);
        $prices = $this->resolveMarketPrices();

        $this->captureResult('researchResult', 'research.form', fn () => app(GamePurchaseCostCalculator::class)->research(
            array_map('intval', $validated['research']['current']),
            array_map('intval', $validated['research']['target']),
            (bool) $validated['research']['military_doctrine'],
            $prices,
        ));
    }

    public function calculateMilitary(): void
    {
        $this->resetValidation();
        $this->militaryResult = null;
        $rules = [
            'military.wartime' => ['boolean'],
            'military.imperialism' => ['boolean'],
            'military.government_support_agency' => ['boolean'],
            'military.bureau_of_domestic_affairs' => ['boolean'],
        ];
        foreach (MilitaryCostCalculator::UNITS as $unit) {
            $rules["military.quantities.{$unit}"] = ['required', 'integer', 'min:0', 'max:100000000'];
        }
        foreach (MilitaryCostCalculator::RESEARCH_FIELDS as $field) {
            $rules["military.research.{$field}"] = ['required', 'integer', 'min:0', 'max:20'];
        }
        $validated = $this->validate($rules);
        $form = $validated['military'];
        $prices = $this->resolveMarketPrices();

        $this->captureResult('militaryResult', 'military.form', fn () => app(MilitaryCostCalculator::class)->calculate(
            array_map('intval', $form['quantities']),
            array_map('intval', $form['research']),
            (bool) $form['wartime'],
            (bool) $form['imperialism'],
            (bool) $form['government_support_agency'],
            (bool) $form['bureau_of_domestic_affairs'],
            $prices,
        ));
    }

    public function calculateEconomics(): void
    {
        $this->resetValidation();
        $this->economicsResult = null;
        $validated = $this->validate([
            'economics.continent' => ['required', 'string', 'in:NA,SA,EU,AF,AS,AU,AN'],
            'economics.num_cities' => ['required', 'integer', 'min:1', 'max:1000'],
            'economics.domestic_policy' => ['required', 'string', 'in:NONE,OPEN_MARKETS'],
            'economics.treasure_income_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'economics.infrastructure' => ['required', 'numeric', 'min:0', 'max:20000'],
            'economics.land' => ['required', 'numeric', 'min:0', 'max:20000'],
            'economics.age_days' => ['required', 'integer', 'min:1', 'max:100000'],
            'economics.powered' => ['boolean'],
            'economics.roi_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'economics.buildings' => ['required', 'array'],
            'economics.buildings.*' => ['required', 'integer', 'min:0'],
            'economics.projects' => ['required', 'array'],
            'economics.projects.*' => ['boolean'],
        ]);
        $form = $validated['economics'];
        $prices = $this->resolveMarketPrices();
        $radiation = app(RadiationService::class)->latestOrRefresh(false);
        $this->setWorldContext($radiation);

        $this->captureResult('economicsResult', 'economics.form', fn () => app(CityEconomicsCalculator::class)->calculate(
            nationInput: [
                'continent' => $form['continent'],
                'num_cities' => (int) $form['num_cities'],
                'domestic_policy' => $form['domestic_policy'],
                'treasure_income_modifier' => (float) $form['treasure_income_percent'] / 100,
            ],
            cityInput: [
                'infrastructure' => (float) $form['infrastructure'],
                'land' => (float) $form['land'],
                'age_days' => (int) $form['age_days'],
                'powered' => (bool) $form['powered'],
            ],
            buildings: array_map('intval', $form['buildings']),
            projects: array_map('boolval', $form['projects']),
            radiationSnapshot: $radiation,
            prices: $prices,
            asOf: now()->toImmutable(),
            roiDays: (int) $form['roi_days'],
        ));
    }

    public function render(): View
    {
        return view('livewire.calculator-center')
            ->extends('layouts.main')
            ->section('content')
            ->title('Calculator Center');
    }

    private function applyNationPrefill(): void
    {
        $nation = Auth::user()?->nation;

        if (! $nation instanceof Nation) {
            $this->nationContext = ['available' => false];

            return;
        }

        $nation->loadMissing(['cities', 'military']);
        $ownedProjects = $nation->projects;
        $hasProject = fn (string $project): bool => (bool) data_get($ownedProjects, $project, false);
        $hasGsa = $hasProject('government_support_agency');
        $hasBda = $hasProject('bureau_of_domestic_affairs');
        $this->city = [
            'city_number' => max(1, (int) $nation->num_cities + 1),
            'top_twenty_average' => $this->city['top_twenty_average'],
            'manifest_destiny' => $nation->domestic_policy === 'MANIFEST_DESTINY',
            'government_support_agency' => $hasGsa,
            'bureau_of_domestic_affairs' => $hasBda,
        ];
        $this->infrastructure = [...$this->infrastructure,
            'urbanization' => $nation->domestic_policy === 'URBANIZATION',
            'center_for_civil_engineering' => $hasProject('center_for_civil_engineering'),
            'advanced_engineering_corps' => $hasProject('advanced_engineering_corps'),
            'government_support_agency' => $hasGsa,
            'bureau_of_domestic_affairs' => $hasBda,
        ];
        $this->land = [...$this->land,
            'rapid_expansion' => $nation->domestic_policy === 'RAPID_EXPANSION',
            'arable_land_agency' => $hasProject('arable_land_agency'),
            'advanced_engineering_corps' => $hasProject('advanced_engineering_corps'),
            'government_support_agency' => $hasGsa,
            'bureau_of_domestic_affairs' => $hasBda,
        ];
        $this->project = [...$this->project,
            'technological_advancement' => $nation->domestic_policy === 'TECHNOLOGICAL_ADVANCEMENT',
            'government_support_agency' => $hasGsa,
            'bureau_of_domestic_affairs' => $hasBda,
        ];
        $research = [
            'ground_capacity' => (int) ($nation->ground_capacity_research ?? 0),
            'ground_cost' => (int) ($nation->ground_cost_research ?? 0),
            'air_capacity' => (int) ($nation->air_capacity_research ?? 0),
            'air_cost' => (int) ($nation->air_cost_research ?? 0),
            'naval_capacity' => (int) ($nation->naval_capacity_research ?? 0),
            'naval_cost' => (int) ($nation->naval_cost_research ?? 0),
        ];
        $this->research = [
            'current' => $research,
            'target' => $research,
            'military_doctrine' => $hasProject('military_doctrine'),
        ];
        $this->military = [...$this->military,
            'quantities' => collect(MilitaryCostCalculator::UNITS)
                ->mapWithKeys(fn (string $unit): array => [$unit => (int) ($nation->military?->{$unit} ?? 0)])
                ->all(),
            'research' => [
                'ground_cost' => $research['ground_cost'],
                'ground_capacity' => $research['ground_capacity'],
                'air_cost' => $research['air_cost'],
                'air_capacity' => $research['air_capacity'],
                'naval_cost' => $research['naval_cost'],
                'naval_capacity' => $research['naval_capacity'],
            ],
            'wartime' => ((int) $nation->offensive_wars_count + (int) $nation->defensive_wars_count) > 0,
            'imperialism' => $nation->domestic_policy === 'IMPERIALISM',
            'government_support_agency' => $hasGsa,
            'bureau_of_domestic_affairs' => $hasBda,
        ];
        $this->economics = [...$this->economics,
            'continent' => (string) $nation->continent,
            'num_cities' => max(1, (int) $nation->num_cities),
            'domestic_policy' => $nation->domestic_policy === 'OPEN_MARKETS' ? 'OPEN_MARKETS' : 'NONE',
            'treasure_income_percent' => (float) ($nation->treasure_income_modifier ?? 0.0) * 100,
            'projects' => collect(CityEconomicsCalculator::ECONOMY_PROJECTS)
                ->mapWithKeys(fn (string $label, string $project): array => [$project => $hasProject($project)])
                ->all(),
        ];
        $this->nationCities = $nation->cities
            ->sortBy('id')
            ->map(fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name ?: "City {$city->id}",
                'infrastructure' => (float) $city->infrastructure,
                'land' => (float) $city->land,
            ])
            ->values()
            ->all();
        $firstCity = $nation->cities->sortBy('id')->first();
        if ($firstCity instanceof City) {
            $this->selectedCityId = $firstCity->id;
            $this->applyCityPrefill($firstCity);
        }
        $this->nationContext = [
            'available' => true,
            'nation_id' => $nation->id,
            'nation_name' => $nation->nation_name,
            'calculated_from' => $this->latestTimestamp([
                $nation->economy_context_synced_at,
                $nation->updated_at,
                $firstCity?->updated_at,
            ]),
        ];
    }

    private function applyCityPrefill(City $city): void
    {
        $this->infrastructure['current'] = (float) $city->infrastructure;
        $this->infrastructure['target'] = (float) $city->infrastructure;
        $this->land['current'] = (float) $city->land;
        $this->land['target'] = (float) $city->land;
        $this->economics['infrastructure'] = (float) $city->infrastructure;
        $this->economics['land'] = (float) $city->land;
        $this->economics['powered'] = (bool) $city->powered;
        $this->economics['age_days'] = max(1, (int) Carbon::parse($city->date)->diffInDays(now()));
        $this->economics['buildings'] = collect(EconomyRules::BUILD_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => max(0, (int) ($city->{$field} ?? 0))])
            ->all();
    }

    private function loadCityAverage(): void
    {
        $average = SettingService::getCityAverage();
        $updatedAt = SettingService::getCityAverageUpdatedAt();
        $this->city['top_twenty_average'] = $average;
        $this->cityAverageContext = [
            'available' => $average !== null,
            'value' => $average,
            'calculated_at' => $updatedAt?->toIso8601String(),
            'stale' => $updatedAt === null || $updatedAt->lt(now()->subHours(30)),
        ];
    }

    private function resolveMarketPrices(): ?MarketPriceSet
    {
        try {
            $prices = app(MarketValuationService::class)->current();
            $this->marketContext = [
                'available' => true,
                'calculated_at' => $prices->calculatedAt?->toIso8601String(),
                'stale' => $prices->stale,
                'basis' => $prices->basis,
                'fallback_resources' => $prices->fallbackResources,
            ];

            return $prices;
        } catch (ProfitabilityPricingUnavailable $exception) {
            $this->marketContext = [
                'available' => false,
                'stale' => true,
                'message' => $exception->getMessage(),
            ];

            return null;
        }
    }

    private function loadWorldContext(): void
    {
        $this->setWorldContext(app(RadiationService::class)->latestOrRefresh(false));
    }

    private function setWorldContext(?RadiationSnapshot $snapshot): void
    {
        $this->worldContext = [
            'available' => $snapshot !== null,
            'snapshot_at' => $snapshot?->snapshot_at?->toIso8601String(),
            'game_date' => $snapshot?->game_date?->toDateString(),
            'stale' => $snapshot?->snapshot_at === null
                || $snapshot->snapshot_at->lt(now()->subHours(EconomyRules::WORLD_SNAPSHOT_MAX_AGE_HOURS)),
        ];
    }

    private function captureResult(string $property, string $errorKey, callable $calculation): void
    {
        try {
            $this->{$property} = $calculation()->toArray();
        } catch (InvalidArgumentException $exception) {
            $this->addError($errorKey, $exception->getMessage());
        }
    }

    private function latestTimestamp(array $timestamps): ?string
    {
        return collect($timestamps)
            ->filter()
            ->map(fn (mixed $timestamp): Carbon => Carbon::parse($timestamp))
            ->sortByDesc(fn (Carbon $timestamp): int => $timestamp->getTimestamp())
            ->first()
            ?->toIso8601String();
    }
}
