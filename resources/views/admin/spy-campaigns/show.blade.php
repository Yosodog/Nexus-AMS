@extends('layouts.admin')

@section('content')
    @php
        $allowedTabs = ['overview', 'rounds', 'alliances', 'settings'];
        $requestedTab = request()->query('tab');
        $oldTab = old('active_tab');
        $activeTab = in_array($requestedTab, $allowedTabs, true)
            ? $requestedTab
            : (in_array($oldTab, $allowedTabs, true) ? $oldTab : 'overview');
        $statusValue = $campaign->status instanceof \BackedEnum ? $campaign->status->value : (string) $campaign->status;
        $statusClass = match ($statusValue) {
            'active' => 'badge-success',
            'draft' => 'badge-ghost',
            default => 'badge-neutral',
        };
    @endphp

    <div x-data="{ activeTab: @js($activeTab) }">
        <x-header :title="$campaign->name" separator>
            <x-slot:subtitle>
                <span class="inline-flex items-center gap-2">
                    <span>Status:</span>
                    <span class="badge {{ $statusClass }} text-uppercase">{{ $statusValue }}</span>
                    @if (filled($campaign->description))
                        <span class="text-base-content/60">{{ $campaign->description }}</span>
                    @endif
                </span>
            </x-slot:subtitle>
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <span class="tooltip tooltip-left" data-tip="Each round runs a single op type. Assignments respect spy range, slots, policy synergy, and your min success target.">
                        <x-icon name="o-question-mark-circle" class="size-5 text-base-content/50" />
                    </span>
                    <a href="{{ route('admin.spy-campaigns.index') }}" class="btn btn-ghost btn-sm">Back</a>
                </div>
            </x-slot:actions>
        </x-header>

        <div class="alert alert-info mb-6">
            <x-icon name="o-information-circle" class="size-5 shrink-0" />
            <span class="text-sm">
                Add allied and enemy alliances, create rounds with a single spy op, then generate assignments. The system stays within spy range, respects slot caps, picks the lowest safety level that clears the success threshold, and boosts matches with policy synergy.
            </span>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-stat
                title="Allied / Enemy Alliances"
                :value="$campaign->alliances->where('role', 'ally')->count() . ' / ' . $campaign->alliances->where('role', 'enemy')->count()"
                icon="o-globe-alt"
                description="Alliance pools used to build aggressors and targets"
            />
            <x-stat
                title="Rounds"
                :value="number_format($campaign->rounds->count())"
                icon="o-rectangle-stack"
                :description="$latestRound?->op_type?->name ?? 'No rounds yet'"
                color="text-primary"
            />
            <x-stat
                title="Assignments"
                :value="number_format($latestRound?->assignments->count() ?? 0)"
                icon="o-paper-airplane"
                description="Assignments in the latest round"
                color="text-info"
            />
            <x-stat
                title="Avg Odds"
                :value="number_format($oddsDistribution->avg() ?? 0, 1) . '%'"
                icon="o-chart-bar-square"
                description="Mean odds of the latest round"
                color="text-warning"
            />
        </div>

        <div class="mb-6 flex flex-wrap gap-2 border-b border-base-300 pb-2">
            @foreach ($allowedTabs as $tab)
                <button
                    type="button"
                    class="tab tab-bordered"
                    :class="activeTab === '{{ $tab }}' ? 'tab-active' : ''"
                    @click="activeTab = '{{ $tab }}'"
                >
                    {{ ucfirst($tab) }}
                </button>
            @endforeach
        </div>

        <section x-show="activeTab === 'overview'" x-cloak class="space-y-6">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <x-card title="Odds Distribution" subtitle="Spread of calculated odds for the latest round.">
                    <canvas id="oddsChart" height="200"></canvas>
                </x-card>

                <x-card title="Impact Projections" subtitle="Expected impact scaled by op type and policy synergy.">
                    <canvas id="impactChart" height="200"></canvas>
                </x-card>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-card title="Slot Usage" subtitle="Max 2 offensive slots per aggressor and 3 defensive per target.">
                    <canvas id="slotsChart" height="200"></canvas>
                </x-card>

                <x-card title="Top Targets" subtitle="Sorted by expected impact.">
                    <div class="space-y-3">
                        @forelse ($topTargets as $target)
                            <div class="flex items-center justify-between rounded-box border border-base-300 px-4 py-3">
                                <span class="font-medium">{{ $target['defender'] ?? 'Unknown' }}</span>
                                <span class="badge badge-primary">{{ number_format($target['impact'], 1) }} impact</span>
                            </div>
                        @empty
                            <div class="text-sm text-base-content/60">No assignments yet.</div>
                        @endforelse
                    </div>
                </x-card>
            </div>
        </section>

        <section x-show="activeTab === 'rounds'" x-cloak>
            <x-card title="Rounds" :subtitle="$campaign->rounds->count() . ' rounds configured'">
                <x-slot:menu>
                    <button class="btn btn-primary btn-sm" type="button" onclick="document.getElementById('addRoundModal').showModal()">
                        <x-icon name="o-plus-circle" class="size-4" />
                        Add Round
                    </button>
                </x-slot:menu>

                <div class="overflow-x-auto rounded-box border border-base-300">
                    <table class="table table-zebra">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Op</th>
                            <th>Status</th>
                            <th>Assignments</th>
                            <th>Average Odds</th>
                            <th class="text-right">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($campaign->rounds->sortBy('round_number') as $round)
                            <tr>
                                <td>{{ $round->round_number }}</td>
                                <td>{{ $round->op_type?->name ?? 'n/a' }}</td>
                                <td><span class="badge badge-ghost text-uppercase">{{ $round->status }}</span></td>
                                <td>{{ $round->assignments->count() }}</td>
                                <td>{{ number_format($round->assignments->avg('calculated_odds') ?? 0, 1) }}%</td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('admin.spy-campaigns.rounds.show', $round) }}" class="btn btn-ghost btn-sm">
                                            <x-icon name="o-list-bullet" class="size-4" />
                                        </a>
                                        <form action="{{ route('admin.spy-campaigns.rounds.generate', $round) }}" method="post">
                                            @csrf
                                            <input type="hidden" name="active_tab" x-model="activeTab">
                                            <button class="btn btn-primary btn-outline btn-sm" type="submit">
                                                <x-icon name="o-cpu-chip" class="size-4" />
                                            </button>
                                        </form>
                                        <button class="btn btn-success btn-outline btn-sm" type="button" onclick="document.getElementById('messageModal-{{ $round->id }}').showModal()">
                                            <x-icon name="o-envelope" class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <dialog id="messageModal-{{ $round->id }}" class="modal">
                                <div class="modal-box max-w-3xl">
                                    <form method="post" action="{{ route('admin.spy-campaigns.rounds.message', $round) }}" class="space-y-4">
                                        @csrf
                                        <input type="hidden" name="active_tab" x-model="activeTab">

                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <h3 class="text-lg font-semibold">Send messages for Round {{ $round->round_number }}</h3>
                                                <p class="text-sm text-base-content/60">Assignments auto-append target names, op type, safety, odds, and PW espionage links.</p>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('messageModal-{{ $round->id }}').close()">✕</button>
                                        </div>

                                        <label class="block space-y-2">
                                            <span class="text-sm font-medium">Message body</span>
                                            <textarea name="message" class="textarea textarea-bordered min-h-40 w-full" rows="6" placeholder="Include tactics, timing, and reminders."></textarea>
                                        </label>

                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="btn btn-ghost" onclick="document.getElementById('messageModal-{{ $round->id }}').close()">Cancel</button>
                                            <button type="submit" class="btn btn-success">Queue Messages</button>
                                        </div>
                                    </form>
                                </div>
                                <form method="dialog" class="modal-backdrop"><button>close</button></form>
                            </dialog>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </section>

        <section x-show="activeTab === 'alliances'" x-cloak>
            <div class="grid gap-6 lg:grid-cols-2">
                <x-card title="Allied" subtitle="Alliances used to source aggressors.">
                    <div class="space-y-3">
                        @forelse ($campaign->alliances->where('role', 'ally') as $alliance)
                            <div class="flex items-center justify-between rounded-box border border-base-300 px-4 py-3">
                                <span>{{ $alliance->alliance?->name ?? 'Unknown' }}</span>
                                <form method="post" action="{{ route('admin.spy-campaigns.alliances.destroy', [$campaign, $alliance]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="active_tab" x-model="activeTab">
                                    <button class="btn btn-error btn-outline btn-sm" type="submit">
                                        <x-icon name="o-x-mark" class="size-4" />
                                    </button>
                                </form>
                            </div>
                        @empty
                            <div class="text-sm text-base-content/60">No allied alliances added yet.</div>
                        @endforelse
                    </div>

                    <div class="mt-4 border-t border-base-300 pt-4">
                        <form method="post" action="{{ route('admin.spy-campaigns.alliances.store', $campaign) }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                            @csrf
                            <input type="hidden" name="role" value="ally">
                            <input type="hidden" name="active_tab" x-model="activeTab">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Alliance ID</span>
                                <input type="number" name="alliance_id" class="input input-bordered w-full" placeholder="1234">
                            </label>
                            <div class="flex items-end">
                                <button class="btn btn-primary w-full sm:w-auto" type="submit">Add</button>
                            </div>
                        </form>
                    </div>
                </x-card>

                <x-card title="Enemy" subtitle="Alliances used to source targets.">
                    <div class="space-y-3">
                        @forelse ($campaign->alliances->where('role', 'enemy') as $alliance)
                            <div class="flex items-center justify-between rounded-box border border-base-300 px-4 py-3">
                                <span>{{ $alliance->alliance?->name ?? 'Unknown' }}</span>
                                <form method="post" action="{{ route('admin.spy-campaigns.alliances.destroy', [$campaign, $alliance]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="active_tab" x-model="activeTab">
                                    <button class="btn btn-error btn-outline btn-sm" type="submit">
                                        <x-icon name="o-x-mark" class="size-4" />
                                    </button>
                                </form>
                            </div>
                        @empty
                            <div class="text-sm text-base-content/60">No enemy alliances added yet.</div>
                        @endforelse
                    </div>

                    <div class="mt-4 border-t border-base-300 pt-4">
                        <form method="post" action="{{ route('admin.spy-campaigns.alliances.store', $campaign) }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                            @csrf
                            <input type="hidden" name="role" value="enemy">
                            <input type="hidden" name="active_tab" x-model="activeTab">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Alliance ID</span>
                                <input type="number" name="alliance_id" class="input input-bordered w-full" placeholder="5678">
                            </label>
                            <div class="flex items-end">
                                <button class="btn btn-error w-full sm:w-auto" type="submit">Add</button>
                            </div>
                        </form>
                    </div>
                </x-card>
            </div>
        </section>

        <section x-show="activeTab === 'settings'" x-cloak>
            <x-card title="Campaign Settings" subtitle="Adjust defaults used by future assignment generation.">
                <form method="post" action="{{ route('admin.spy-campaigns.update', $campaign) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="active_tab" x-model="activeTab">

                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_14rem_14rem]">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Name</span>
                            <input type="text" name="name" class="input input-bordered w-full" value="{{ old('name', $campaign->name) }}">
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Status</span>
                            <select name="status" class="select select-bordered w-full">
                                @foreach (['draft', 'active', 'archived'] as $status)
                                    <option value="{{ $status }}" @selected($campaign->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block space-y-2">
                            <span class="flex items-center gap-2 text-sm font-medium">
                                Min success %
                                <span class="tooltip" data-tip="Assignments test safety 1→3 and stop at the first level meeting this threshold; otherwise they’re flagged low-odds.">
                                    <x-icon name="o-question-mark-circle" class="size-4 text-base-content/50" />
                                </span>
                            </span>
                            <input type="number" name="settings[min_success_chance]" class="input input-bordered w-full" min="0" max="100" step="1" value="{{ old('settings.min_success_chance', $campaign->settings['min_success_chance'] ?? 65) }}">
                        </label>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Description</span>
                        <textarea name="description" class="textarea textarea-bordered min-h-28 w-full" rows="3">{{ old('description', $campaign->description) }}</textarea>
                    </label>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">Save settings</button>
                    </div>
                </form>
            </x-card>
        </section>
    </div>

    <dialog id="addRoundModal" class="modal">
        <div class="modal-box max-w-2xl">
            <form method="post" action="{{ route('admin.spy-campaigns.rounds.store', $campaign) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="active_tab" value="rounds">

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">Add Round</h3>
                        <p class="text-sm text-base-content/60">Each round generates assignments for one operation type.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('addRoundModal').close()">✕</button>
                </div>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Operation Type</span>
                    <select name="op_type" class="select select-bordered w-full" required>
                        @foreach ($opTypes as $type)
                            <option value="{{ $type->value }}">{{ \Illuminate\Support\Str::headline(strtolower($type->name)) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Round # (optional)</span>
                    <input type="number" name="round_number" class="input input-bordered w-full" min="1">
                </label>

                <label class="block space-y-2">
                    <span class="flex items-center gap-2 text-sm font-medium">
                        Min success target (%)
                        <span class="tooltip" data-tip="Assignments pick the lowest safety level that meets this chance; lower odds are still assigned but flagged.">
                            <x-icon name="o-question-mark-circle" class="size-4 text-base-content/50" />
                        </span>
                    </span>
                    <input type="number" name="min_success_chance" class="input input-bordered w-full" min="0" max="100" step="1" value="{{ $campaign->settings['min_success_chance'] ?? 65 }}">
                </label>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('addRoundModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
@endsection

@pushOnce('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const odds = {{ Js::from($oddsDistribution->all()) }};
        const impacts = {{ Js::from($impactSeries->all()) }};
        const attackerSlots = {{ Js::from($slotUsage['attackers'] ?? []) }};
        const defenderSlots = {{ Js::from($slotUsage['defenders'] ?? []) }};

        const oddsCtx = document.getElementById('oddsChart');
        if (oddsCtx && odds.length) {
            new Chart(oddsCtx, {
                type: 'bar',
                data: {
                    labels: odds.map((_, idx) => `#${idx + 1}`),
                    datasets: [{
                        label: 'Odds %',
                        data: odds,
                        backgroundColor: '#2563eb'
                    }]
                }
            });
        }

        const impactCtx = document.getElementById('impactChart');
        if (impactCtx && impacts.length) {
            new Chart(impactCtx, {
                type: 'line',
                data: {
                    labels: impacts.map((_, idx) => `#${idx + 1}`),
                    datasets: [{
                        label: 'Expected impact',
                        data: impacts,
                        borderColor: '#d946ef',
                        fill: false,
                        tension: 0.3,
                    }]
                }
            });
        }

        const slotsCtx = document.getElementById('slotsChart');
        if (slotsCtx) {
            new Chart(slotsCtx, {
                type: 'bar',
                data: {
                    labels: ['Aggressors', 'Targets'],
                    datasets: [{
                        label: 'Slots used',
                        data: [Object.values(attackerSlots).reduce((sum, val) => sum + val, 0), Object.values(defenderSlots).reduce((sum, val) => sum + val, 0)],
                        backgroundColor: ['#22c55e', '#f97316'],
                    }]
                }
            });
        }
    </script>
@endPushOnce
