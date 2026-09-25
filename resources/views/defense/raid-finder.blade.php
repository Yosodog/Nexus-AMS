@php
    $raidAvailabilityEndpoint = $availabilityEndpoint ?? '';

    if ($raidAvailabilityEndpoint === '' && \Illuminate\Support\Facades\Route::has('api.raid-finder.availability')) {
        $raidAvailabilityEndpoint = route('api.raid-finder.availability', [
            'nation_id' => '__NATION__',
            'target_id' => '__TARGET__',
        ]);
    }
@endphp

@extends('layouts.main')

@section('content')
    <div
        class="mx-auto w-full min-w-0 space-y-6"
        data-raid-finder
        data-raid-finder-endpoint="{{ $finderEndpoint }}"
        data-raid-availability-endpoint="{{ $raidAvailabilityEndpoint }}"
        aria-busy="true"
    >
        <header class="nexus-page-header">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Offense prep</p>
                <h1 class="nexus-page-title">Raid Finder</h1>
                <p class="nexus-page-summary">
                    Find eligible targets, compare expected returns, and verify availability before you commit a war slot.
                </p>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('defense.raid-results'))
                <div class="nexus-page-header__actions">
                    <a href="{{ route('defense.raid-results') }}" class="btn btn-outline btn-sm">My raid results</a>
                </div>
            @endif
        </header>

        <form class="nexus-form-section" data-raid-finder-form>
            <div class="nexus-form-section-header">
                <h2 class="nexus-section-title">Search and filters</h2>
                <p class="nexus-body-muted mt-1">Filters and sorting stay in the URL and survive refresh or retry.</p>
            </div>

            <div class="nexus-form-grid">
                <x-form.input
                    id="raid-nation-id"
                    name="nation_id"
                    type="number"
                    label="Nation ID"
                    hint="Targets are calculated for this alliance nation."
                    :value="$nationId"
                    min="1"
                    inputmode="numeric"
                    required
                />
                <x-form.input
                    id="raid-target-search"
                    name="q"
                    type="search"
                    label="Leader or alliance"
                    hint="Filters the loaded target list."
                    autocomplete="off"
                />
                <x-form.input id="raid-min-cities" name="min_cities" type="number" label="Minimum cities" min="0" inputmode="numeric" optional />
                <x-form.input id="raid-max-cities" name="max_cities" type="number" label="Maximum cities" min="0" inputmode="numeric" optional />
                <x-form.select id="raid-max-wars" name="max_wars" label="Maximum defensive wars" optional>
                    <option value="">Any eligible count</option>
                    <option value="0">0 wars</option>
                    <option value="1">1 war</option>
                    <option value="2">2 wars</option>
                </x-form.select>
                <x-form.input
                    id="raid-min-loot"
                    name="min_loot"
                    type="number"
                    label="Minimum historical loot"
                    min="0"
                    step="1000000"
                    inputmode="numeric"
                    optional
                />
                <x-form.select id="raid-inactivity" name="inactivity" label="Minimum inactivity" optional>
                    <option value="">Any activity</option>
                    <option value="3">Inactive 3+ days</option>
                    <option value="7">Inactive 7+ days</option>
                    <option value="14">Inactive 14+ days</option>
                    <option value="30">Inactive 30+ days</option>
                </x-form.select>
                <x-form.select id="raid-military-suitability" name="military" label="Military suitability" optional>
                    <option value="">Any suitability</option>
                    <option value="suitable">Suitable or better</option>
                    <option value="strong">Strong advantage</option>
                </x-form.select>
                <x-form.input
                    id="raid-min-return"
                    name="min_return"
                    type="number"
                    label="Minimum expected return"
                    min="0"
                    step="1000000"
                    inputmode="numeric"
                    optional
                />
                <x-form.select id="raid-sort" name="sort" label="Sort by" optional>
                    <option value="expected_net">Expected net return</option>
                    <option value="conservative">Conservative return</option>
                    <option value="gross">Gross loot</option>
                    <option value="efficiency">Slot efficiency</option>
                </x-form.select>
            </div>

            <div class="nexus-form-actions">
                <button type="button" class="btn btn-ghost" data-raid-clear-filters>Clear filters</button>
                <x-async.button type="submit" class="btn-primary" busy-label="Refreshing…" data-raid-refresh>
                    Refresh targets
                </x-async.button>
            </div>
        </form>

        <section aria-labelledby="raid-targets-heading">
            <div class="space-y-4">
                <x-async.state
                    state="loading"
                    title="Finding eligible targets"
                    message="Checking Politics & War and the latest saved target data."
                    data-raid-state-panel="loading"
                />
                <x-async.state
                    state="success"
                    title="Targets ready"
                    data-raid-state-panel="success"
                    hidden
                />
                <x-async.state
                    state="empty"
                    title="No eligible targets"
                    data-raid-state-panel="empty"
                    hidden
                >
                    Last checked <time data-raid-updated>not yet</time>.
                </x-async.state>
                <x-async.state
                    state="filtered_empty"
                    title="No targets match these filters"
                    data-raid-state-panel="filtered_empty"
                    hidden
                >
                    Target data last updated <time data-raid-updated>not yet</time>.
                </x-async.state>
                <x-async.state
                    state="stale"
                    title="Showing saved targets"
                    retry
                    retry-label="Refresh now"
                    data-raid-state-panel="stale"
                    hidden
                />
                <x-async.state
                    state="rate_limited"
                    title="Refresh temporarily paused"
                    retry
                    data-raid-state-panel="rate_limited"
                    hidden
                />
                <x-async.state
                    state="temporary_failure"
                    title="Raid targets are temporarily unavailable"
                    retry
                    data-raid-state-panel="temporary_failure"
                    hidden
                />
                <x-async.state
                    state="offline"
                    title="Reconnect to refresh targets"
                    retry
                    data-raid-state-panel="offline"
                    hidden
                />
                <x-async.state
                    state="session_expired"
                    title="Your session expired"
                    message="Reload this page and sign in again. Your filters remain in the URL."
                    data-raid-state-panel="session_expired"
                    hidden
                />
                <x-async.state
                    state="error"
                    retry
                    data-raid-state-panel="error"
                    hidden
                />

                <div data-raid-skeleton>
                    <x-async.skeleton label="Loading raid targets" :rows="5" />
                </div>

                <div class="nexus-panel" data-raid-results hidden>
                    <div class="nexus-panel__header">
                        <div>
                            <h2 id="raid-targets-heading" class="nexus-section-title">Eligible targets</h2>
                            <p class="nexus-body-muted mt-1">
                                Last updated <time data-raid-updated>not yet</time>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <span class="nexus-status nexus-status--neutral" data-raid-result-count>0 results</span>
                            <span class="nexus-status nexus-status--neutral" data-raid-sort-summary>Sorted by expected net return</span>
                        </div>
                    </div>

                    <div class="nexus-table-shell border-0 rounded-none">
                        <table class="nexus-table" data-sortable="false">
                            <caption class="sr-only">Raid targets ranked by the selected expected return metric.</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Leader</th>
                                    <th scope="col">Alliance</th>
                                    <th scope="col">Cities</th>
                                    <th scope="col">Last active</th>
                                    <th scope="col">Score</th>
                                    <th scope="col">Wars</th>
                                    <th scope="col">Expected return</th>
                                    <th scope="col">Last beige</th>
                                    <th scope="col" class="text-right">Details</th>
                                </tr>
                            </thead>
                            <tbody data-raid-results-body></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <template data-raid-row-template>
            <tr data-raid-row>
                <td>
                    <a class="link link-hover font-semibold text-primary" data-raid-nation-link target="_blank" rel="noopener noreferrer"></a>
                    <p class="mt-1 text-xs nexus-text-muted" data-raid-intelligence></p>
                </td>
                <td data-raid-alliance></td>
                <td data-raid-cities></td>
                <td><time data-raid-last-active></time></td>
                <td data-raid-score></td>
                <td data-raid-wars></td>
                <td>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="font-semibold" data-raid-expected-net></div>
                        <span class="badge badge-sm badge-warning" data-raid-valuation-badge hidden></span>
                    </div>
                    <div class="mt-1 text-xs nexus-text-muted" data-raid-gross-loot></div>
                    <div class="mt-1 text-xs" data-raid-return-status></div>
                </td>
                <td data-raid-last-beige></td>
                <td class="text-right">
                    <button type="button" class="btn btn-outline btn-xs" data-raid-inspect aria-expanded="false">Inspect</button>
                </td>
            </tr>
            <tr data-raid-detail-row hidden>
                <td colspan="9" class="bg-base-200/50 p-4">
                    <div class="grid items-start gap-6 max-xl:sticky max-xl:left-0 max-xl:w-[calc(100vw-4rem)] xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)_minmax(0,1fr)]" data-raid-detail-panel>
                        <div class="space-y-3">
                            <div>
                                <h3 class="mt-1 text-lg font-semibold" data-raid-detail-title></h3>
                            </div>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm" aria-label="Target intelligence details">
                                <div><dt class="nexus-text-muted">Military fit</dt><dd class="font-medium" data-raid-military-suitability>Unavailable</dd></div>
                                <div><dt class="nexus-text-muted">Confidence</dt><dd class="font-medium" data-raid-confidence>Unavailable</dd></div>
                                <div><dt class="nexus-text-muted">Win probability</dt><dd class="font-medium" data-raid-win-probability>Unavailable</dd></div>
                                <div><dt class="nexus-text-muted">Duration</dt><dd class="font-medium" data-raid-duration>Unavailable</dd></div>
                                <div><dt class="nexus-text-muted">Observed</dt><dd class="font-medium" data-raid-detail-intelligence>Unavailable</dd></div>
                                <div><dt class="nexus-text-muted">Availability</dt><dd class="font-medium" data-raid-availability-status>Not checked</dd></div>
                            </dl>
                            <p class="text-sm nexus-text-muted" data-raid-availability-reasons></p>
                            <p class="text-xs nexus-text-muted" data-raid-availability-checked></p>
                            <button type="button" class="btn btn-ghost btn-xs" data-raid-recheck>
                                <span data-async-button-spinner class="loading loading-spinner loading-xs" aria-hidden="true" hidden></span>
                                <span data-async-button-label>Check availability</span>
                            </button>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <p class="text-xs uppercase tracking-[0.18em] nexus-text-muted">Recommended approach</p>
                                <p class="mt-1 font-semibold" data-raid-approach-label>Unavailable</p>
                                <p class="mt-2 text-sm font-medium tabular-nums" data-raid-approach-metrics></p>
                                <p class="mt-1 text-sm nexus-text-muted" data-raid-approach-description></p>
                            </div>
                            <details class="border-t border-base-300 pt-3">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">Compare other approaches</summary>
                                <div class="mt-3 divide-y divide-base-300" data-raid-approaches></div>
                            </details>
                            <details class="border-t border-base-300 pt-3">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">How this estimate is calculated</summary>
                                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed" data-raid-assumptions></ul>
                            </details>
                            <details class="border-t border-base-300 pt-3">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">Military comparison</summary>
                                <dl class="mt-3 grid grid-cols-3 gap-x-3 gap-y-1 text-xs" data-raid-military-comparison></dl>
                            </details>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs uppercase tracking-[0.18em] nexus-text-muted">Return breakdown</p>
                                    <span class="badge badge-sm badge-warning" data-raid-detail-valuation-badge hidden></span>
                                </div>
                                <p class="mt-1 text-xs nexus-text-muted" data-raid-valuation-range hidden></p>
                                <dl class="mt-2 space-y-2 text-sm" data-raid-components></dl>
                                <p class="mt-2 text-xs nexus-text-muted" data-raid-cost-note hidden></p>
                            </div>
                            <div data-raid-unknown-components-section hidden>
                                <p class="text-xs uppercase tracking-[0.18em] nexus-text-muted">Unknown components</p>
                                <ul class="mt-1 list-disc space-y-1 pl-5 text-sm" data-raid-unknown-components></ul>
                            </div>
                            <details class="border-t border-base-300 pt-3">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">View resource quantities</summary>
                                <div class="mt-3 grid grid-cols-2 gap-2 text-sm" data-raid-resources></div>
                            </details>
                            <details class="border-t border-base-300 pt-3">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">View outcome scenarios</summary>
                                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm" data-raid-scenarios></ul>
                            </details>
                        </div>
                        <section class="min-w-0 space-y-3 border-t border-base-300 pt-4 xl:col-span-3" aria-label="Calculation evidence">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="font-semibold">Behind the estimate</h3>
                                <p class="text-xs nexus-text-muted" data-raid-calculation-stamp></p>
                            </div>
                            <p class="text-sm nexus-text-muted" data-raid-main-uncertainty></p>
                            <details class="border-t border-base-300 pt-3" data-raid-evidence-section data-raid-disclosure-key="evidence">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">Loot evidence <span class="ml-2 font-normal nexus-text-muted" data-raid-evidence-summary></span></summary>
                                <div class="mt-3 space-y-3 text-sm" data-raid-evidence></div>
                            </details>
                            <details class="border-t border-base-300 pt-3" data-raid-stockpile-section data-raid-disclosure-key="stockpile">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">Stockpile calculation</summary>
                                <div class="mt-3 space-y-3 text-sm" data-raid-stockpile-work></div>
                            </details>
                            <details class="border-t border-base-300 pt-3" data-raid-outcome-section data-raid-disclosure-key="outcome">
                                <summary class="cursor-pointer text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary">Expected raid outcome</summary>
                                <div class="mt-3 space-y-3 text-sm" data-raid-outcome-work></div>
                            </details>
                        </section>
                    </div>
                </td>
            </tr>
        </template>

        <noscript>
            <x-async.state
                state="error"
                title="JavaScript is required for Raid Finder"
                message="Enable JavaScript to load and filter live targets."
            />
        </noscript>
    </div>
@endsection
