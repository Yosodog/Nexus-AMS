@extends('layouts.main')

@section('content')
    <div
        class="mx-auto w-full min-w-0 space-y-6"
        data-raid-finder
        data-raid-finder-endpoint="{{ route('api.raid-finder.show') }}"
        aria-busy="true"
    >
        <header class="nexus-page-header">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Offense prep</p>
                <h1 class="nexus-page-title">Raid Finder</h1>
                <p class="nexus-page-summary">
                    Find eligible targets, narrow the list without another API request, and verify when the data was refreshed.
                </p>
            </div>
        </header>

        <form class="nexus-form-section" data-raid-finder-form>
            <div class="nexus-form-section-header">
                <h2 class="nexus-section-title">Search and filters</h2>
                <p class="nexus-body-muted mt-1">Filters stay in the URL and survive refresh or retry.</p>
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
                    label="Minimum estimated loot"
                    min="0"
                    step="1000000"
                    inputmode="numeric"
                    optional
                />
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
                    title="Refresh paused by Politics & War"
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
                        <span class="nexus-status nexus-status--neutral" data-raid-result-count>0 results</span>
                    </div>

                    <div class="nexus-table-shell border-0 rounded-none">
                        <table class="nexus-table" data-sortable="true">
                            <thead>
                                <tr>
                                    <th>Leader</th>
                                    <th>Alliance</th>
                                    <th>Cities</th>
                                    <th>Last active</th>
                                    <th>Score</th>
                                    <th>Wars</th>
                                    <th>Est. loot</th>
                                    <th>Last beige</th>
                                </tr>
                            </thead>
                            <tbody data-raid-results-body></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <template data-raid-row-template>
            <tr>
                <td><a class="link link-hover font-semibold text-primary" data-raid-nation-link target="_blank" rel="noopener noreferrer"></a></td>
                <td data-raid-alliance></td>
                <td data-raid-cities></td>
                <td><time data-raid-last-active></time></td>
                <td data-raid-score></td>
                <td data-raid-wars></td>
                <td data-raid-loot></td>
                <td data-raid-last-beige></td>
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
