@extends('layouts.main')

@section('content')
    <div
        class="mx-auto w-full min-w-0 space-y-6"
        data-raid-finder
        data-raid-finder-endpoint="{{ $finderEndpoint }}"
        data-raid-availability-endpoint="{{ $availabilityEndpoint }}"
        data-raid-claims-endpoint="{{ $claimsEndpoint }}"
        data-nation-id="{{ $nationId }}"
    >
        <header class="nexus-page-header">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Offense prep</p>
                <h1 class="nexus-page-title">Raid finder</h1>
                <p class="nexus-page-summary" data-raid-attacker-summary>Ranking targets in your declaration range by expected profit.</p>
                <p class="mt-2 text-sm text-warning" data-raid-planning-warning hidden>
                    All your offensive slots are in use. Targets are shown for planning only.
                </p>
            </div>
            <div class="nexus-page-header__actions">
                <a href="{{ route('defense.raid-results') }}" class="btn btn-outline btn-sm">My raid results</a>
            </div>
        </header>

        <p class="sr-only" role="status" aria-live="polite" data-raid-status></p>

        <form class="nexus-form-section" data-raid-filters>
            <div class="flex flex-wrap items-end gap-4">
                @if ($canQueryOthers)
                    <label class="form-control w-36">
                        <span class="label-text">Nation ID</span>
                        <input type="number" name="nation_id" min="1" inputmode="numeric" class="input input-bordered input-sm" value="{{ $nationId }}">
                    </label>
                @endif
                <label class="form-control w-44">
                    <span class="label-text">Minimum expected profit</span>
                    <input type="number" name="min_expected_net" step="100000" inputmode="numeric" class="input input-bordered input-sm" placeholder="Any">
                </label>
                <label class="form-control w-44">
                    <span class="label-text">Inactive for at least (days)</span>
                    <input type="number" name="min_inactive_days" min="0" max="365" inputmode="numeric" class="input input-bordered input-sm" placeholder="Any">
                </label>
                <label class="form-control w-48">
                    <span class="label-text">Include beige ending within (turns)</span>
                    <input type="number" name="beige_within_turns" min="0" max="24" value="0" inputmode="numeric" class="input input-bordered input-sm">
                </label>
                <label class="form-control w-52">
                    <span class="label-text">Alliance</span>
                    <select name="alliance_scope" class="select select-bordered select-sm">
                        <option value="any">Any</option>
                        <option value="unaligned">Unaligned &amp; applicants</option>
                        <option value="aligned">Aligned</option>
                    </select>
                </label>
                <label class="label cursor-pointer gap-2">
                    <input type="checkbox" name="beatable_only" value="1" class="checkbox checkbox-sm">
                    <span class="label-text">Only targets I can beat</span>
                </label>
                <label class="label cursor-pointer gap-2">
                    <input type="checkbox" name="hide_claimed" value="1" class="checkbox checkbox-sm">
                    <span class="label-text">Hide claimed</span>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-raid-refresh>
                        <span data-async-button-spinner class="loading loading-spinner loading-xs" aria-hidden="true" hidden></span>
                        <span data-async-button-label>Refresh</span>
                    </button>
                </div>
            </div>
        </form>

        <div data-raid-skeleton>
            <x-async.skeleton label="Loading raid targets" :rows="6" />
        </div>

        <div role="alert" class="alert alert-error" data-raid-error hidden>
            <div>
                <p data-raid-error-message></p>
                <p class="text-xs opacity-80" data-raid-error-support></p>
            </div>
        </div>

        <div class="nexus-empty-state" data-raid-empty hidden>
            <div>
                <h2 class="font-semibold">No targets match these filters</h2>
                <p class="mt-1 text-sm text-base-content/70">Widen the filters or include targets whose beige ends soon.</p>
            </div>
        </div>

        <section class="nexus-panel" aria-labelledby="raid-targets-heading" data-raid-results hidden>
            <div class="nexus-panel__header">
                <div>
                    <h2 id="raid-targets-heading" class="nexus-section-title">Targets by expected profit</h2>
                    <p class="mt-1 text-sm text-base-content/70">
                        Updated <time data-raid-generated data-raid-relative></time> ·
                        <span data-raid-candidate-count></span> candidates valued ·
                        model <span data-raid-model-version></span>
                    </p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <caption class="sr-only">Raid targets ranked by expected profit.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Target</th>
                            <th scope="col">Cities</th>
                            <th scope="col">Last active</th>
                            <th scope="col">Military</th>
                            <th scope="col">Slots</th>
                            <th scope="col">Expected profit</th>
                            <th scope="col">Confidence</th>
                            <th scope="col">Win / victory</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-raid-rows></tbody>
                </table>
            </div>
        </section>

        <template data-raid-row-template>
            <tr data-raid-row>
                <td class="font-semibold tabular-nums" data-raid-rank></td>
                <td>
                    <a class="link link-hover font-semibold" target="_blank" rel="noopener noreferrer" data-raid-nation-link></a>
                    <div class="text-xs text-base-content/70">
                        <span data-raid-alliance></span>
                        <span class="badge badge-ghost badge-xs" data-raid-position hidden></span>
                    </div>
                    <div class="text-xs" data-raid-claim-status></div>
                </td>
                <td class="tabular-nums" data-raid-cities></td>
                <td>
                    <time data-raid-last-active data-raid-relative></time>
                    <span class="badge badge-ghost badge-xs" data-raid-activity></span>
                </td>
                <td class="whitespace-nowrap text-xs tabular-nums" data-raid-military></td>
                <td class="tabular-nums" data-raid-slots></td>
                <td class="tabular-nums">
                    <div class="font-semibold" data-raid-expected-net></div>
                    <div class="text-xs text-base-content/70" data-raid-expected-range></div>
                </td>
                <td><span class="badge badge-sm" data-raid-confidence></span></td>
                <td class="whitespace-nowrap tabular-nums" data-raid-odds></td>
                <td>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" class="btn btn-ghost btn-xs" aria-expanded="false" data-raid-details>Details</button>
                        <button type="button" class="btn btn-ghost btn-xs" data-raid-check>
                            <span data-async-button-spinner class="loading loading-spinner loading-xs" aria-hidden="true" hidden></span>
                            <span data-async-button-label>Check availability</span>
                        </button>
                        <button type="button" class="btn btn-outline btn-xs" data-raid-claim></button>
                        <a class="btn btn-primary btn-xs" target="_blank" rel="noopener noreferrer" data-raid-declare>Declare</a>
                    </div>
                </td>
            </tr>
        </template>

        <template data-raid-detail-template>
            <tr data-raid-detail-row>
                <td colspan="10" class="bg-base-200">
                    <div class="grid gap-6 lg:grid-cols-3">
                        <div>
                            <h3 class="font-semibold">Expected profit breakdown</h3>
                            <table class="table table-xs">
                                <tbody data-raid-components></tbody>
                            </table>
                        </div>
                        <div>
                            <h3 class="font-semibold">Estimated stockpile</h3>
                            <p class="text-xs text-base-content/70" data-raid-evidence></p>
                            <table class="table table-xs">
                                <tbody data-raid-stockpile></tbody>
                            </table>
                        </div>
                        <div class="space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <span class="badge badge-outline" data-raid-competition></span>
                                <span class="badge badge-outline" data-raid-counter></span>
                            </div>
                            <div>
                                <h3 class="font-semibold">Assumptions</h3>
                                <ul class="list-disc pl-5 text-sm" data-raid-assumptions></ul>
                            </div>
                            <div data-raid-availability hidden>
                                <h3 class="font-semibold">Availability</h3>
                                <p class="text-sm font-medium" data-raid-availability-status></p>
                                <ul class="list-disc pl-5 text-sm" data-raid-availability-reasons></ul>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        </template>
    </div>
@endsection
