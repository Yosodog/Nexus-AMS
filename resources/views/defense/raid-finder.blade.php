@extends('layouts.main')

@section('content')
    <div class="mx-auto space-y-6" x-data="raidFinder()" x-init="loadRaids()">
        <div class="rounded-2xl border border-base-300 bg-base-100 p-6 shadow">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Offense prep</p>
                    <h1 class="text-3xl font-bold">Raid Finder</h1>
                    <p class="text-sm text-base-content/70">Fetch fresh raid targets by nation ID and sort on the fly.</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end w-full sm:w-auto">
                    <input id="nationId" type="number" class="input input-bordered w-full sm:w-40" x-model="nationId" placeholder="Nation ID"/>
                    <button class="btn btn-primary w-full sm:w-auto" @click="loadRaids()">Refresh</button>
                </div>
            </div>
        </div>

        <!-- Loading spinner -->
        <div x-show="loading" class="flex justify-center items-center py-10">
            <span class="loading loading-spinner loading-lg text-primary"></span>
        </div>

        <!-- Table -->
        <div x-show="!loading" x-cloak class="card bg-base-100 shadow border border-base-300">
            <div class="card-body">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="card-title">Targets</h2>
                    <span class="badge badge-outline" x-text="`${targets.length} results`"></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full text-sm">
                        <thead>
                        <tr>
                            <th>Leader</th>
                            <th>Alliance</th>
                            <th>Cities</th>
                            <th>Last Active</th>
                            <th>Score</th>
                            <th>Wars</th>
                            <th>Est. Loot</th>
                            <th>Last Beige</th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="target in targets" :key="target.nation.id">
                            <tr>
                                <td>
                                    <a :href="`https://politicsandwar.com/nation/id=${target.nation.id}`"
                                       class="link link-hover text-primary font-semibold"
                                       x-text="target.nation.leader_name"></a>
                                </td>
                                <td x-text="target.nation.alliance?.name ?? 'None'"></td>
                                <td x-text="target.nation.num_cities"></td>
                                <td x-text="target.nation.last_active"></td>
                                <td x-text="target.nation.score.toFixed(2)"></td>
                                <td x-text="target.defensive_wars"></td>
                                <td x-text="formatMoney(target.value)"></td>
                                <td x-text="formatMoney(target.last_beige ?? 0)"></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function raidFinder() {
            return {
                nationId: Number('{{ $nationId }}'),
                loading: true,
                targets: [],

                loadRaids() {
                    this.loading = true;

                    // Step 1: CSRF for Sanctum
                    fetch('/sanctum/csrf-cookie', {
                        method: 'GET',
                        credentials: 'include'
                    }).then(() => {
                        // Step 2: fetch raid targets
                        return fetch(`/api/v1/defense/raid-finder/${this.nationId}`, {
                            method: 'GET',
                            credentials: 'include',
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                    })
                        .then(res => {
                            if (!res.ok) throw new Error('Failed to load raid targets');
                            return res.json();
                        })
                        .then(data => {
                            this.targets = data;
                        })
                        .catch(err => {
                            alert('Failed to fetch raid targets');
                            console.error(err);
                        })
                        .finally(() => this.loading = false);
                },

                formatMoney(value) {
                    return `$${Number(value).toLocaleString()}`;
                }
            }
        }
    </script>
@endsection
