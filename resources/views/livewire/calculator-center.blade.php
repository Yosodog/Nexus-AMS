<div class="nexus-stack">
    <header class="nexus-page-header sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <div class="nexus-page-header__copy">
            <p class="nexus-kicker">Planning tools</p>
            <h1 class="nexus-page-title">Calculator center</h1>
            <p class="nexus-page-summary max-w-3xl">
                Plan purchases and city economics with the same rules Nexus uses for grants, rebuilding, and profitability.
                Every result lists active modifiers, assumptions, raw resources, and market valuation.
            </p>
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="prefillFromNation" wire:loading.attr="disabled" wire:target="prefillFromNation">
            <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" />
            Use my nation
        </button>
    </header>

    @error('prefill')
        <div class="alert alert-error" role="alert"><span>{{ $message }}</span></div>
    @enderror

    <section class="grid gap-3 md:grid-cols-3" aria-label="Calculator data sources">
        <article class="rounded-lg border border-base-300 bg-base-100 p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Nation data</h2>
                <span class="nexus-status {{ ($nationContext['available'] ?? false) ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                    {{ ($nationContext['available'] ?? false) ? 'Available' : 'Manual only' }}
                </span>
            </div>
            <p class="mt-2 text-sm nexus-text-muted">
                @if(filled($nationContext['calculated_from'] ?? null))
                    Source timestamp <time datetime="{{ $nationContext['calculated_from'] }}">{{ \Carbon\Carbon::parse($nationContext['calculated_from'])->toDayDateTimeString() }}</time>
                @else
                    No nation timestamp is available.
                @endif
            </p>
        </article>
        <article class="rounded-lg border border-base-300 bg-base-100 p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Market prices</h2>
                <span class="nexus-status {{ !($marketContext['available'] ?? false) || ($marketContext['stale'] ?? true) ? 'nexus-status--warning' : 'nexus-status--success' }}">
                    {{ !($marketContext['available'] ?? false) ? 'Unavailable' : (($marketContext['stale'] ?? true) ? 'Stale' : 'Current') }}
                </span>
            </div>
            <p class="mt-2 text-sm nexus-text-muted">
                @if(filled($marketContext['calculated_at'] ?? null))
                    Source timestamp <time datetime="{{ $marketContext['calculated_at'] }}">{{ \Carbon\Carbon::parse($marketContext['calculated_at'])->toDayDateTimeString() }}</time>
                @elseif($marketContext['available'] ?? false)
                    Using {{ $marketContext['basis'] ?? 'fallback market prices' }}; this source has no snapshot timestamp.
                @else
                    Raw results remain available without market valuation.
                @endif
            </p>
        </article>
        <article class="rounded-lg border border-base-300 bg-base-100 p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">World context</h2>
                <span class="nexus-status {{ !($worldContext['available'] ?? false) || ($worldContext['stale'] ?? true) ? 'nexus-status--warning' : 'nexus-status--success' }}">
                    {{ !($worldContext['available'] ?? false) ? 'Unavailable' : (($worldContext['stale'] ?? true) ? 'Stale' : 'Current') }}
                </span>
            </div>
            <p class="mt-2 text-sm nexus-text-muted">
                @if(filled($worldContext['snapshot_at'] ?? null))
                    Radiation timestamp <time datetime="{{ $worldContext['snapshot_at'] }}">{{ \Carbon\Carbon::parse($worldContext['snapshot_at'])->toDayDateTimeString() }}</time>
                @else
                    Required only when a build contains farms.
                @endif
            </p>
        </article>
    </section>

    @if($nationCities !== [])
        <section class="nexus-panel p-4" aria-labelledby="nation-city-prefill-heading">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <x-form.select id="nation-city-prefill" name="nation_city_prefill" label="Prefill from one of my cities" hint="Only cities belonging to your authenticated nation can be loaded." wire:change="prefillCity($event.target.value)">
                    @foreach($nationCities as $nationCity)
                        <option value="{{ $nationCity['id'] }}" @selected($selectedCityId === $nationCity['id'])>
                            {{ $nationCity['name'] }} · {{ number_format($nationCity['infrastructure'], 2) }} infra · {{ number_format($nationCity['land'], 2) }} land
                        </option>
                    @endforeach
                </x-form.select>
                <p id="nation-city-prefill-heading" class="text-sm nexus-text-muted">Targets remain editable after prefill.</p>
            </div>
        </section>
    @endif

    <nav class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Calculator index">
        @foreach([
            ['city-cost', 'City purchase', 'o-building-office-2'],
            ['infrastructure-cost', 'Infrastructure', 'o-building-library'],
            ['land-cost', 'Land', 'o-map'],
            ['project-cost', 'Projects', 'o-rocket-launch'],
            ['research-cost', 'Military research', 'o-academic-cap'],
            ['military-cost', 'Military units', 'o-shield-check'],
            ['city-economics', 'City economics', 'o-chart-bar'],
        ] as [$anchor, $label, $icon])
            <a href="#{{ $anchor }}" class="flex min-h-12 items-center gap-3 rounded-lg border border-base-300 bg-base-100 px-4 py-3 font-semibold transition hover:border-primary/50 hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon :name="$icon" class="size-5 text-primary" aria-hidden="true" />
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <section id="city-cost" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="city-cost-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">Development</p><h2 id="city-cost-heading" class="nexus-section-title">City purchase cost</h2><p class="nexus-body-muted mt-1">Uses the dynamic top-20% city average and current domestic-policy synergies.</p></div></div>
        <form wire:submit="calculateCity" class="grid gap-5 p-4 sm:p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form.input id="city-number" name="city.city_number" label="City being purchased" type="number" min="1" max="10000" step="1" :value="$city['city_number']" wire:model.number="city.city_number" required />
                <x-form.input id="city-average" name="city.top_twenty_average" label="Top-20% city average" type="number" min="0.01" max="10000" step="0.0001" :value="$city['top_twenty_average']" wire:model.number="city.top_twenty_average" required>
                    <x-slot:help>
                        @if(filled($cityAverageContext['calculated_at'] ?? null))
                            Cached {{ \Carbon\Carbon::parse($cityAverageContext['calculated_at'])->diffForHumans() }}{{ ($cityAverageContext['stale'] ?? true) ? ' (stale)' : '' }}.
                        @else
                            Enter the current API value manually.
                        @endif
                    </x-slot:help>
                </x-form.input>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                <x-form.toggle id="city-manifest" name="city.manifest_destiny" label="Manifest Destiny" :checked="$city['manifest_destiny']" wire:model="city.manifest_destiny" />
                <x-form.toggle id="city-gsa" name="city.government_support_agency" label="Government Support Agency" hint="Applies only with Manifest Destiny." :checked="$city['government_support_agency']" wire:model="city.government_support_agency" />
                <x-form.toggle id="city-bda" name="city.bureau_of_domestic_affairs" label="Bureau of Domestic Affairs" hint="Applies only with Manifest Destiny." :checked="$city['bureau_of_domestic_affairs']" wire:model="city.bureau_of_domestic_affairs" />
            </div>
            @error('city.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateCity">Calculate city cost</button>
        </form>
        @if($cityResult)<x-calculators.result :result="$cityResult" class="m-4 sm:m-5" />@endif
    </section>

    <section id="infrastructure-cost" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="infrastructure-cost-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">Development</p><h2 id="infrastructure-cost-heading" class="nexus-section-title">Infrastructure purchase cost</h2><p class="nexus-body-muted mt-1">Purchase from a current level to a target level in one city.</p></div></div>
        <form wire:submit="calculateInfrastructure" class="grid gap-5 p-4 sm:p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form.input id="infra-current" name="infrastructure.current" label="Current infrastructure" type="number" min="0" max="20000" step="0.01" :value="$infrastructure['current']" wire:model.number="infrastructure.current" required />
                <x-form.input id="infra-target" name="infrastructure.target" label="Target infrastructure" type="number" min="0" max="20000" step="0.01" :value="$infrastructure['target']" wire:model.number="infrastructure.target" required />
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <x-form.toggle id="infra-urbanization" name="infrastructure.urbanization" label="Urbanization" :checked="$infrastructure['urbanization']" wire:model="infrastructure.urbanization" />
                <x-form.toggle id="infra-cfce" name="infrastructure.center_for_civil_engineering" label="Center for Civil Engineering" :checked="$infrastructure['center_for_civil_engineering']" wire:model="infrastructure.center_for_civil_engineering" />
                <x-form.toggle id="infra-aec" name="infrastructure.advanced_engineering_corps" label="Advanced Engineering Corps" :checked="$infrastructure['advanced_engineering_corps']" wire:model="infrastructure.advanced_engineering_corps" />
                <x-form.toggle id="infra-gsa" name="infrastructure.government_support_agency" label="Government Support Agency" hint="Applies only with Urbanization." :checked="$infrastructure['government_support_agency']" wire:model="infrastructure.government_support_agency" />
                <x-form.toggle id="infra-bda" name="infrastructure.bureau_of_domestic_affairs" label="Bureau of Domestic Affairs" hint="Applies only with Urbanization." :checked="$infrastructure['bureau_of_domestic_affairs']" wire:model="infrastructure.bureau_of_domestic_affairs" />
            </div>
            @error('infrastructure.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateInfrastructure">Calculate infrastructure cost</button>
        </form>
        @if($infrastructureResult)<x-calculators.result :result="$infrastructureResult" class="m-4 sm:m-5" />@endif
    </section>

    <section id="land-cost" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="land-cost-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">Development</p><h2 id="land-cost-heading" class="nexus-section-title">Land purchase cost</h2><p class="nexus-body-muted mt-1">Purchase land from a current level to a target level in one city.</p></div></div>
        <form wire:submit="calculateLand" class="grid gap-5 p-4 sm:p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form.input id="land-current" name="land.current" label="Current land" type="number" min="0" max="20000" step="0.01" :value="$land['current']" wire:model.number="land.current" required />
                <x-form.input id="land-target" name="land.target" label="Target land" type="number" min="0" max="20000" step="0.01" :value="$land['target']" wire:model.number="land.target" required />
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <x-form.toggle id="land-rapid" name="land.rapid_expansion" label="Rapid Expansion" :checked="$land['rapid_expansion']" wire:model="land.rapid_expansion" />
                <x-form.toggle id="land-ala" name="land.arable_land_agency" label="Arable Land Agency" :checked="$land['arable_land_agency']" wire:model="land.arable_land_agency" />
                <x-form.toggle id="land-aec" name="land.advanced_engineering_corps" label="Advanced Engineering Corps" :checked="$land['advanced_engineering_corps']" wire:model="land.advanced_engineering_corps" />
                <x-form.toggle id="land-gsa" name="land.government_support_agency" label="Government Support Agency" hint="Applies only with Rapid Expansion." :checked="$land['government_support_agency']" wire:model="land.government_support_agency" />
                <x-form.toggle id="land-bda" name="land.bureau_of_domestic_affairs" label="Bureau of Domestic Affairs" hint="Applies only with Rapid Expansion." :checked="$land['bureau_of_domestic_affairs']" wire:model="land.bureau_of_domestic_affairs" />
            </div>
            @error('land.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateLand">Calculate land cost</button>
        </form>
        @if($landResult)<x-calculators.result :result="$landResult" class="m-4 sm:m-5" />@endif
    </section>

    <section id="project-cost" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="project-cost-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">National investment</p><h2 id="project-cost-heading" class="nexus-section-title">Project purchase cost</h2><p class="nexus-body-muted mt-1">Shows the project’s money and resource requirements separately.</p></div></div>
        <form wire:submit="calculateProject" class="grid gap-5 p-4 sm:p-5">
            <x-form.select id="project-select" name="project.project" label="National project" wire:model="project.project" required>
                @foreach($projectOptions as $key => $option)<option value="{{ $key }}" @selected($project['project'] === $key)>{{ $option['label'] }}</option>@endforeach
            </x-form.select>
            <div class="grid gap-3 md:grid-cols-3">
                <x-form.toggle id="project-ta" name="project.technological_advancement" label="Technological Advancement" :checked="$project['technological_advancement']" wire:model="project.technological_advancement" />
                <x-form.toggle id="project-gsa" name="project.government_support_agency" label="Government Support Agency" hint="Applies only with Technological Advancement." :checked="$project['government_support_agency']" wire:model="project.government_support_agency" />
                <x-form.toggle id="project-bda" name="project.bureau_of_domestic_affairs" label="Bureau of Domestic Affairs" hint="Applies only with Technological Advancement." :checked="$project['bureau_of_domestic_affairs']" wire:model="project.bureau_of_domestic_affairs" />
            </div>
            @error('project.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateProject">Calculate project cost</button>
        </form>
        @if($projectResult)<x-calculators.result :result="$projectResult" class="m-4 sm:m-5" />@endif
    </section>

    <section id="research-cost" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="research-cost-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">Military development</p><h2 id="research-cost-heading" class="nexus-section-title">Military research cost</h2><p class="nexus-body-muted mt-1">All six current levels matter because total and branch progress affect each upgrade.</p></div></div>
        <form wire:submit="calculateResearch" class="grid gap-5 p-4 sm:p-5">
            <div class="overflow-x-auto">
                <table class="table min-w-[42rem]">
                    <thead><tr><th scope="col">Research branch</th><th scope="col">Current</th><th scope="col">Target</th></tr></thead>
                    <tbody>
                        @foreach(['ground_capacity' => 'Ground capacity', 'ground_cost' => 'Ground cost', 'air_capacity' => 'Air capacity', 'air_cost' => 'Air cost', 'naval_capacity' => 'Naval capacity', 'naval_cost' => 'Naval cost'] as $key => $label)
                            @php
                                $currentResearchError = $errors->first("research.current.{$key}");
                                $targetResearchError = $errors->first("research.target.{$key}");
                            @endphp
                            <tr>
                                <th scope="row" class="font-medium">{{ $label }}</th>
                                <td>
                                    <input
                                        aria-label="Current {{ $label }}"
                                        @if($currentResearchError) aria-invalid="true" aria-describedby="research-current-{{ $key }}-error" @endif
                                        class="input input-sm w-28 {{ $currentResearchError ? 'input-error' : '' }}"
                                        type="number"
                                        min="0"
                                        max="20"
                                        step="1"
                                        wire:model.number="research.current.{{ $key }}"
                                    >
                                    @if($currentResearchError)<p id="research-current-{{ $key }}-error" class="mt-1 text-xs text-error" role="alert">{{ $currentResearchError }}</p>@endif
                                </td>
                                <td>
                                    <input
                                        aria-label="Target {{ $label }}"
                                        @if($targetResearchError) aria-invalid="true" aria-describedby="research-target-{{ $key }}-error" @endif
                                        class="input input-sm w-28 {{ $targetResearchError ? 'input-error' : '' }}"
                                        type="number"
                                        min="0"
                                        max="20"
                                        step="1"
                                        wire:model.number="research.target.{{ $key }}"
                                    >
                                    @if($targetResearchError)<p id="research-target-{{ $key }}-error" class="mt-1 text-xs text-error" role="alert">{{ $targetResearchError }}</p>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-form.toggle id="research-doctrine" name="research.military_doctrine" label="Military Doctrine" hint="Reduces all research purchase resources by 5%." :checked="$research['military_doctrine']" wire:model="research.military_doctrine" />
            @error('research.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateResearch">Calculate research cost</button>
        </form>
        @if($researchResult)<x-calculators.result :result="$researchResult" class="m-4 sm:m-5" />@endif
    </section>

    <section id="military-cost" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="military-cost-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">Force budgeting</p><h2 id="military-cost-heading" class="nexus-section-title">Military unit purchase and upkeep</h2><p class="nexus-body-muted mt-1">Calculates one-time purchase resources and daily upkeep without combat consumption.</p></div></div>
        <form wire:submit="calculateMilitary" class="grid gap-6 p-4 sm:p-5">
            <fieldset>
                <legend class="font-semibold">Unit quantities</legend>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach(['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'] as $unit)
                        <x-form.input :id="'military-'.$unit" :name="'military.quantities.'.$unit" :label="str($unit)->headline()" type="number" min="0" max="100000000" step="1" :value="$military['quantities'][$unit]" wire:model.number="military.quantities.{{ $unit }}" required />
                    @endforeach
                </div>
            </fieldset>
            <fieldset>
                <legend class="font-semibold">Research levels</legend>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(['ground_cost', 'ground_capacity', 'air_cost', 'air_capacity', 'naval_cost', 'naval_capacity'] as $field)
                        <x-form.input :id="'military-research-'.$field" :name="'military.research.'.$field" :label="str($field)->headline()" type="number" min="0" max="20" step="1" :value="$military['research'][$field]" wire:model.number="military.research.{{ $field }}" required />
                    @endforeach
                </div>
            </fieldset>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <x-form.toggle id="military-wartime" name="military.wartime" label="Wartime upkeep" :checked="$military['wartime']" wire:model="military.wartime" />
                <x-form.toggle id="military-imperialism" name="military.imperialism" label="Imperialism" :checked="$military['imperialism']" wire:model="military.imperialism" />
                <x-form.toggle id="military-gsa" name="military.government_support_agency" label="Government Support Agency" hint="Applies only with Imperialism." :checked="$military['government_support_agency']" wire:model="military.government_support_agency" />
                <x-form.toggle id="military-bda" name="military.bureau_of_domestic_affairs" label="Bureau of Domestic Affairs" hint="Applies only with Imperialism." :checked="$military['bureau_of_domestic_affairs']" wire:model="military.bureau_of_domestic_affairs" />
            </div>
            @error('military.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateMilitary">Calculate military costs</button>
        </form>
        @if($militaryResult)<x-calculators.result :result="$militaryResult" class="m-4 sm:m-5" />@endif
    </section>

    <section id="city-economics" class="nexus-panel scroll-mt-28 p-4 sm:p-6" aria-labelledby="city-economics-heading">
        <div class="nexus-panel__header"><div><p class="nexus-kicker">Economic planning</p><h2 id="city-economics-heading" class="nexus-section-title">City and build economics</h2><p class="nexus-body-muted mt-1">Reuses Nexus’s profitability engine for income, expenses, resource output, and improvement payback.</p></div></div>
        <form wire:submit="calculateEconomics" class="grid gap-7 p-4 sm:p-5">
            <fieldset>
                <legend class="font-semibold">City context</legend>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-form.select id="economics-continent" name="economics.continent" label="Continent" wire:model="economics.continent" required>
                        @foreach(['NA' => 'North America', 'SA' => 'South America', 'EU' => 'Europe', 'AF' => 'Africa', 'AS' => 'Asia', 'AU' => 'Australia', 'AN' => 'Antarctica'] as $code => $label)<option value="{{ $code }}" @selected($economics['continent'] === $code)>{{ $label }}</option>@endforeach
                    </x-form.select>
                    <x-form.input id="economics-cities" name="economics.num_cities" label="Nation city count" type="number" min="1" max="1000" step="1" :value="$economics['num_cities']" wire:model.number="economics.num_cities" required />
                    <x-form.input id="economics-infra" name="economics.infrastructure" label="Infrastructure" type="number" min="0" max="20000" step="0.01" :value="$economics['infrastructure']" wire:model.number="economics.infrastructure" required />
                    <x-form.input id="economics-land" name="economics.land" label="Land" type="number" min="0" max="20000" step="0.01" :value="$economics['land']" wire:model.number="economics.land" required />
                    <x-form.input id="economics-age" name="economics.age_days" label="City age (days)" type="number" min="1" max="100000" step="1" :value="$economics['age_days']" wire:model.number="economics.age_days" required />
                    <x-form.select id="economics-policy" name="economics.domestic_policy" label="Income policy" wire:model="economics.domestic_policy" required>
                        <option value="NONE" @selected($economics['domestic_policy'] === 'NONE')>No income modifier</option><option value="OPEN_MARKETS" @selected($economics['domestic_policy'] === 'OPEN_MARKETS')>Open Markets</option>
                    </x-form.select>
                    <x-form.input id="economics-treasure" name="economics.treasure_income_percent" label="Treasure income bonus (%)" type="number" min="0" max="100" step="0.01" :value="$economics['treasure_income_percent']" wire:model.number="economics.treasure_income_percent" required />
                    <x-form.input id="economics-roi-days" name="economics.roi_days" label="ROI period (days)" type="number" min="1" max="3650" step="1" :value="$economics['roi_days']" wire:model.number="economics.roi_days" required />
                </div>
                <div class="mt-4 max-w-xl"><x-form.toggle id="economics-powered" name="economics.powered" label="City reports as powered" hint="Installed capacity must also cover the selected infrastructure." :checked="$economics['powered']" wire:model="economics.powered" /></div>
            </fieldset>

            @foreach($buildingGroups as $group => $fields)
                <fieldset>
                    <legend class="font-semibold">{{ $group }}</legend>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($fields as $field)
                            <x-form.input :id="'economics-building-'.$field" :name="'economics.buildings.'.$field" :label="str($field)->headline()" type="number" min="0" step="1" :value="$economics['buildings'][$field]" wire:model.number="economics.buildings.{{ $field }}" required />
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <fieldset>
                <legend class="font-semibold">Owned projects that affect this build</legend>
                <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($economyProjectOptions as $projectKey => $label)
                        <x-form.toggle :id="'economics-project-'.$projectKey" :name="'economics.projects.'.$projectKey" :label="$label" :checked="$economics['projects'][$projectKey]" wire:model="economics.projects.{{ $projectKey }}" />
                    @endforeach
                </div>
            </fieldset>

            @error('economics.form')<p class="text-sm font-medium text-error" role="alert">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full sm:w-auto sm:justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="calculateEconomics">Calculate city economics</button>
        </form>
        @if($economicsResult)<x-calculators.result :result="$economicsResult" class="m-4 sm:m-5" />@endif
    </section>
</div>
