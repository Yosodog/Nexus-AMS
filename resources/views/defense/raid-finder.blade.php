@extends('layouts.main')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-6" x-data="raidFinder()" x-init="loadRaids()">
        <div class="mb-6">
            <h1 class="text-3xl font-bold">Raid Finder</h1>
            <p class="text-sm text-gray-500">Scanning for juicy targets... 💸</p>
        </div>

        <div class="mb-4">
            <label for="nationId" class="label">Enter Nation ID</label>
            <input id="nationId" type="number" class="input input-bordered w-full max-w-xs" x-model="nationId" />
            <button class="btn btn-primary ml-2" @click="loadRaids()">Refresh</button>
        </div>

        <!-- Loading spinner -->
        <div x-show="loading" class="flex justify-center items-center py-10">
            <span class="loading loading-spinner loading-lg text-primary"></span>
        </div>

        <!-- Table -->
        <div x-show="!loading" x-cloak>
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
                        <th>War Policy</th>
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
                            <td x-text="target.nation.war_policy"></td>
                            <td x-text="formatMoney(target.value)"></td>
                            <td x-text="formatMoney(target.last_beige ?? 0)"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
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