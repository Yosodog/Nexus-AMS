@extends('layouts.admin')

@section('content')
    <datalist id="friendly-options-{{ $plan->id }}" x-data>
        <template x-for="friendly in $store?.warPlan?.friendlies || []" :key="friendly.id">
            <option :value="friendly.id" x-text="`${friendly.leader_name} (${friendly.nation_name})`"></option>
        </template>
    </datalist>

    <x-header title="War Plan" separator>
        <x-slot:subtitle>
            {{ $plan->name }}
            <span class="mx-2 text-base-content/40">•</span>
            <span class="badge badge-primary badge-sm uppercase" title="Plan type drives the default war declaration when our members engage targets.">
                {{ $plan->status }}
            </span>
        </x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.war-room') }}" class="btn btn-outline btn-sm">Back to War Room</a>
        </x-slot:actions>
    </x-header>

    <div class="space-y-6">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm text-base-content/60">Targets</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $enemyCount }}</span>
                    <span class="badge badge-ghost" title="Enemy nations tracked with TPS"><i class="o-bolt"></i></span>
                </div>
            </div>
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm text-base-content/60">Assign coverage</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $coverage !== null ? $coverage.'%' : 'n/a' }}</span>
                    <span class="badge badge-primary" title="Assignments / preferred slots">{{ $assignmentCount }} / {{ $preferredSlotsTotal }}</span>
                </div>
            </div>
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm text-base-content/60">Locked slots</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $lockedCount }}</span>
                    <span class="badge badge-success" title="Locked or overridden assignments remain untouched">Safe</span>
                </div>
            </div>
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm text-base-content/60">Preferred wars / nation</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $preferredTargetsPerNation }}</span>
                    <span class="badge badge-info" title="Activity window drives readiness weighting">{{ $plan->activity_window_hours }}h</span>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,0.9fr)]">
            <x-card title="Plan Options">
                <x-slot:menu>
                    <span title="Baseline settings that influence automation heuristics."><i class="o-question-mark-circle"></i></span>
                </x-slot:menu>

                <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-3 text-sm">
                    <div class="font-medium text-base-content/70">Plan Type</div>
                    <div class="text-right uppercase">{{ $warTypes[strtolower($plan->plan_type ?? '')] ?? ucfirst($plan->plan_type ?? 'Unknown') }}</div>

                    <div class="font-medium text-base-content/70" title="Offensive wars we aim to give each friendly before applying slot caps.">Preferred targets / nation</div>
                    <div class="text-right">{{ $preferredTargetsPerNation }}</div>

                    <div class="font-medium text-base-content/70" title="Upper bound when forming automatic strike squads.">Max Squad Size</div>
                    <div class="text-right">{{ $plan->max_squad_size }}</div>

                    <div class="font-medium text-base-content/70" title="Tolerance (±) that keeps squads aligned on readiness.">Cohesion tolerance</div>
                    <div class="text-right">±{{ $plan->squad_cohesion_tolerance }}</div>

                    <div class="font-medium text-base-content/70" title="Recent activity (hours) factored into Target Priority Score.">Activity window</div>
                    <div class="text-right">{{ $plan->activity_window_hours }}h</div>

                    <div class="font-medium text-base-content/70">Discord forum override</div>
                    <div class="text-right">{{ $plan->discord_forum_channel_id ?: 'Default' }}</div>

                    <div class="font-medium text-base-content/70">Suppress counters</div>
                    <div class="text-right">
                        @if ($plan->suppress_counters_when_active)
                            <span class="badge badge-success">Enabled</span>
                        @else
                            <span class="badge badge-ghost">Disabled</span>
                        @endif
                    </div>
                </div>

                <div class="mt-6 border-t border-base-300 pt-4">
                    <form method="post" action="{{ route('admin.war-plans.update', $plan) }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Name</span>
                            <input type="text" name="name" class="input input-bordered w-full" value="{{ old('name', $plan->name) }}" required>
                        </label>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Plan type</span>
                                <select name="plan_type" class="select select-bordered w-full" title="Default declaration applied to new targets.">
                                    @foreach ($warTypes as $key => $label)
                                        <option value="{{ $key }}" @selected(old('plan_type', $plan->plan_type) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Preferred targets / nation</span>
                                <input type="number" name="preferred_targets_per_nation" class="input input-bordered w-full" min="1" max="6" value="{{ old('preferred_targets_per_nation', $plan->preferred_targets_per_nation) }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Max squad size</span>
                                <input type="number" name="max_squad_size" class="input input-bordered w-full" min="1" max="10" value="{{ old('max_squad_size', $plan->max_squad_size) }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Cohesion (±)</span>
                                <input type="number" name="squad_cohesion_tolerance" class="input input-bordered w-full" min="1" max="50" value="{{ old('squad_cohesion_tolerance', $plan->squad_cohesion_tolerance) }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Activity window (h)</span>
                                <input type="number" name="activity_window_hours" class="input input-bordered w-full" min="12" max="240" value="{{ old('activity_window_hours', $plan->activity_window_hours) }}">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Discord forum override</span>
                                <input type="text" name="discord_forum_channel_id" class="input input-bordered w-full" placeholder="Use default from War Room settings" value="{{ old('discord_forum_channel_id', $plan->discord_forum_channel_id) }}">
                            </label>
                        </div>

                        <label class="label cursor-pointer justify-start gap-3">
                            <input class="toggle toggle-primary" type="checkbox" name="suppress_counters_when_active" value="1" {{ old('suppress_counters_when_active', $plan->suppress_counters_when_active) ? 'checked' : '' }}>
                            <span class="label-text">Suppress counters</span>
                        </label>

                        <div>
                            <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                        </div>
                    </form>
                </div>
            </x-card>

            <x-card title="Alliances">
                <x-slot:menu>
                    <span title="Friendly alliances supply our assignment pool. Enemy alliances seed targets."><i class="o-information-circle"></i></span>
                </x-slot:menu>

                <div class="space-y-6">
                    <div>
                        <h6 class="font-semibold">Friendly</h6>
                        <ul class="mt-2 space-y-2">
                            @forelse ($plan->friendlyAlliances as $alliance)
                                <li class="flex items-center justify-between rounded-box border border-base-300 px-3 py-2">
                                    <span>{{ $alliance->alliance->name ?? 'Alliance #'.$alliance->alliance_id }}</span>
                                    <form method="post" action="{{ route('admin.war-plans.alliances.destroy', [$plan, $alliance]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-ghost btn-sm text-error" type="submit" title="Remove">
                                            <i class="o-x-circle"></i>
                                        </button>
                                    </form>
                                </li>
                            @empty
                                <li class="text-base-content/50">Using alliance membership defaults.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div>
                        <h6 class="font-semibold">Enemy</h6>
                        <ul class="mt-2 space-y-2">
                            @forelse ($plan->enemyAlliances as $alliance)
                                <li class="flex items-center justify-between rounded-box border border-base-300 px-3 py-2">
                                    <span>{{ $alliance->alliance->name ?? 'Alliance #'.$alliance->alliance_id }}</span>
                                    <form method="post" action="{{ route('admin.war-plans.alliances.destroy', [$plan, $alliance]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-ghost btn-sm text-error" type="submit" title="Remove">
                                            <i class="o-x-circle"></i>
                                        </button>
                                    </form>
                                </li>
                            @empty
                                <li class="text-base-content/50">Add enemy alliances to drive target discovery.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <div class="mt-6 border-t border-base-300 pt-4">
                    <form method="post" action="{{ route('admin.war-plans.alliances.store', $plan) }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Alliance ID</span>
                                <input type="number" min="1" class="input input-bordered w-full" name="alliance_id" placeholder="1234" required>
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Role</span>
                                <select name="role" class="select select-bordered w-full">
                                    <option value="friendly">Friendly</option>
                                    <option value="enemy">Enemy</option>
                                </select>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-outline btn-primary btn-sm w-full">Add alliance</button>
                        <p class="mb-0 text-sm text-base-content/60">Alliance IDs appear in PW URLs (e.g. <code>.../alliance/id=1234</code>).</p>
                    </form>
                </div>
            </x-card>

            <x-card title="Automation & Notifications">
                <div class="space-y-2">
                    <form method="post" action="{{ route('admin.war-plans.recompute', $plan) }}">
                        @csrf
                        <button class="btn btn-outline btn-sm w-full" type="submit" title="Refresh Target Priority Scores using the latest intelligence.">Recompute priorities</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-plans.auto-assign', $plan) }}">
                        @csrf
                        <button class="btn btn-outline btn-sm w-full" type="submit" title="Regenerate assignments for unlocked slots.">Auto-generate assignments</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-plans.activate', $plan) }}">
                        @csrf
                        <button class="btn btn-outline btn-success btn-sm w-full" type="submit">Activate plan</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-plans.archive', $plan) }}">
                        @csrf
                        <button class="btn btn-outline btn-error btn-sm w-full" type="submit">Archive plan</button>
                    </form>
                </div>

                <div class="mt-6 border-t border-base-300 pt-4">
                    <form method="post" action="{{ route('admin.war-plans.publish', $plan) }}" class="space-y-3">
                        @csrf
                        <label class="label cursor-pointer justify-start gap-3">
                            <input class="checkbox checkbox-sm" type="checkbox" name="notify_in_game" value="1" id="notifyInGame">
                            <span class="label-text">Send in-game mail</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input class="checkbox checkbox-sm" type="checkbox" name="notify_discord_room" value="1" id="notifyDiscordRoom">
                            <span class="label-text">Create Discord War Room</span>
                        </label>
                        <button class="btn btn-primary btn-sm w-full" type="submit">Publish assignments</button>
                        <div class="flex gap-2 pt-1">
                            <a href="{{ route('admin.war-plans.export', $plan) }}" class="btn btn-outline btn-primary flex-1">Export</a>
                            <button class="btn btn-outline flex-1" type="button" onclick="document.getElementById('importPlanModal').showModal()">Import</button>
                        </div>
                    </form>
                </div>
            </x-card>
        </div>

    <dialog class="modal" id="quickAssignModal">
        <div class="modal-box max-w-2xl" x-data>
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold">Quick assign</h3>
                    <p class="text-sm text-base-content/60">Assign an unassigned friendly directly to an available target.</p>
                </div>
                <form method="dialog">
                    <button class="btn btn-ghost btn-sm btn-circle" aria-label="Close">✕</button>
                </form>
            </div>

            <form method="post" :action="$store.warPlan.routes.manualAssignment" class="space-y-4">
                @csrf

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Friendly nation</span>
                    <input type="text" class="input input-bordered w-full" name="friendly_nation_id" id="quickAssignFriendly" :value="$store.warPlan.quickAssign?.id || ''" readonly>
                    <span class="text-sm text-base-content/60" id="quickAssignFriendlyName" x-text="$store.warPlan.quickAssign ? `${$store.warPlan.quickAssign.leader_name} (${$store.warPlan.quickAssign.nation_name})` : 'Select a friendly to assign'"></span>
                    <a x-show="$store.warPlan.quickAssign?.id" :href="`https://politicsandwar.com/nation/id=${$store.warPlan.quickAssign?.id}`" target="_blank" rel="noopener noreferrer" class="link link-hover text-sm">
                        Open nation in-game
                    </a>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Target</span>
                    <select name="war_plan_target_id" class="select select-bordered w-full" required>
                        <template x-for="target in $store.warPlan.targets" :key="target.id">
                            <template x-if="$store.warPlan.targetAvailable(target)">
                                <option :value="target.id" x-text="`${target.nation?.leader_name ?? 'Unknown'} (TPS ${formatNumber(target.target_priority_score, 1)}) • Slots ${(target.assignments_count ?? 0)} / ${$store.warPlan.preferredAssignmentsPerTarget || '-'}`"></option>
                            </template>
                        </template>
                    </select>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Match score (optional)</span>
                    <input type="number" name="match_score" class="input input-bordered w-full" min="0" max="100" placeholder="50">
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('quickAssignModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm" x-data="targetsTable()">
                <div class="card-header flex flex-column flex-lg-row align-items-lg-center gap-3">
                    <div>
                        <h5 class="card-title mb-0">Targets</h5>
                        <small class="text-base-content/50">TPS = Target Priority Score. Hover the badge to inspect factor breakdowns.</small>
                    </div>
                    <form class="ml-auto flex flex-wrap gap-2" method="post" action="{{ route('admin.war-plans.targets.store', $plan) }}">
                        @csrf
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-base-content/70" title="Nation ID pulled from PW profile">Nation ID</span>
                            <input type="number" class="input input-bordered w-full" name="nation_id" min="1" placeholder="e.g. 123456" required>
                        </div>
                        <select name="preferred_war_type" class="select select-bordered select-sm">
                            <option value="">War type (default {{ strtoupper($plan->plan_type) }})</option>
                            @foreach ($warTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-outline btn-primary btn-sm" type="submit">Add target</button>
                    </form>
                </div>
                <div class="p-0">
                    <div class="p-3 flex flex-wrap gap-2 items-center">
                        <div class="flex w-full max-w-sm items-center gap-2">
                            <span class="text-sm font-medium text-base-content/70">Search</span>
                            <input type="search" class="input input-bordered w-full" placeholder="Enemy, alliance, TPS, status" x-model.debounce.300ms="search">
                        </div>
                        <div class="ml-auto flex items-center gap-2">
                            <a href="{{ route('admin.war-plans.targets.export-csv', $plan) }}" class="btn btn-outline btn-success btn-sm">
                                <i class="o-arrow-down-tray mr-1"></i> Export CSV
                            </a>
                            <button type="button" class="btn btn-outline btn-sm" @click="fetchTargets">
                                <i class="o-arrow-path mr-1"></i> Refresh
                            </button>
                            <div class="items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                                <div class="loading loading-spinner loading-sm text-primary" role="status">
                                    
                                </div>
                                <span class="text-base-content/50 small">Loading targets…</span>
                            </div>
                            <span class="text-sm text-error" x-show="error" x-text="error"></span>
                            <button class="btn btn-outline btn-sm" type="button" x-show="error" @click="fetchTargets">Retry</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-box border border-base-300" id="targets-table" x-show="!loading" x-cloak style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="bg-base-200 sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Enemy</th>
                                <th>Alliance</th>
                                <th>TPS</th>
                                <th>Slots</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>War type</th>
                                <th class="text-right">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredTargets.length && !error">
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-base-content/50">No targets yet. Add alliances or seed specific nation IDs above.</td>
                                </tr>
                            </template>
                            <template x-for="target in filteredTargets" :key="target.id">
                                <tr x-show="!isVacation(target)" x-transition>
                                    <td>
                                        <template x-if="target.nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${target.nation.id}`" target="_blank" class="font-semibold">
                                                    <span x-text="target.nation.leader_name"></span>
                                                </a>
                                                <div class="small text-base-content/50" x-text="target.nation.nation_name"></div>
                                                <div class="small">
                                                    Score <span x-text="formatNumber(target.nation.score, 2)"></span>
                                                    • Cities <span x-text="target.nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="small text-base-content/50">
                                                    Soldiers <span x-text="formatNumber(target.nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(target.nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="small text-base-content/50">
                                                    Aircraft <span x-text="formatNumber(target.nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(target.nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation">
                                            <span class="font-semibold text-base-content/50">Unknown</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="target.nation?.alliance">
                                            <div>
                                                <a :href="`https://politicsandwar.com/alliance/id=${target.nation.alliance.id}`" target="_blank" x-text="target.nation.alliance.name"></a>
                                                <div class="small text-base-content/50" x-text="target.nation.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation?.alliance">
                                            <span class="text-base-content/50">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge badge-error" title="Stored TPS meta" x-text="formatNumber(target.target_priority_score, 1)"></span>
                                    </td>
                                    <td>
                                        <span class="badge"
                                              :class="badgeForSlots(target)"
                                              title="Assigned friendlies / preferred slots"
                                              x-text="`${target.assignments_count ?? 0} / ${slotsFor(target)}`">
                                        </span>
                                    </td>
                                    <td>
                                        <template x-if="target.nation">
                                            <div class="flex flex-wrap gap-1">
                                                <span class="badge badge-warning" title="In vacation mode" x-show="(target.nation.vacation_mode_turns ?? 0) > 0">VM</span>
                                                <span class="badge badge-ghost" title="On beige" x-show="(target.nation.beige_turns ?? 0) > 0" x-text="`${target.nation.beige_turns} beige`"></span>
                                                <span class="badge badge-info" title="Active wars"
                                                      x-show="(target.nation.offensive_wars_count ?? 0) + (target.nation.defensive_wars_count ?? 0) > 0"
                                                      x-text="`Wars ${target.nation.offensive_wars_count ?? 0} / ${target.nation.defensive_wars_count ?? 0}`"></span>
                                                <span class="text-base-content/50 small" x-show="(target.nation.offensive_wars_count ?? 0) + (target.nation.defensive_wars_count ?? 0) === 0 && (target.nation.beige_turns ?? 0) === 0 && (target.nation.vacation_mode_turns ?? 0) === 0">No flags</span>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation">
                                            <span class="text-base-content/50">Unknown</span>
                                        </template>
                                    </td>
                                    <td x-text="lastActive(target)"></td>
                                    <td style="width: 180px;">
                                        <form method="post" :action="routes.updateTargetWarType(target.id)">
                                            @csrf
                                            <select class="select select-bordered select-sm"
                                                    name="preferred_war_type"
                                                    title="Preferred declaration for this enemy"
                                                    @change="$event.target.form.submit()">
                                                <template x-for="(label, value) in warTypes" :key="value">
                                                    <option :value="value" :selected="target.preferred_war_type === value" x-text="label"></option>
                                                </template>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button class="btn btn-outline btn-sm" type="button" @click="toggleTargetMeta(target.id)">
                                                Meta
                                            </button>
                                            <button class="btn btn-outline btn-primary btn-sm" type="button" @click="setActiveTarget(target); $nextTick(() => document.getElementById('assignTargetModal').showModal())">
                                                Assign
                                            </button>
                                            <form method="post"
                                                  :action="routes.deleteTarget(target.id)"
                                                  onsubmit="return confirm('Remove this target from the plan?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-outline btn-error btn-sm" type="submit"><i class="o-trash"></i></button>
                                            </form>
                                        </div>
                                        <div class="mt-2 text-left" x-show="isTargetMetaOpen(target.id)" x-transition>
                                            <pre class="mb-0 text-sm text-base-content/60" x-text="prettyMeta(target.meta)"></pre>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <dialog class="modal" id="assignTargetModal">
                    <div class="modal-box max-w-6xl">
                        <div class="mb-4 flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">
                                    Manual assignments
                                    <template x-if="activeTarget?.nation?.id">
                                        <span>
                                            —
                                            <a :href="`https://politicsandwar.com/nation/id=${activeTarget.nation.id}`" target="_blank" rel="noopener noreferrer" class="link link-hover" x-text="activeTarget.nation.leader_name"></a>
                                        </span>
                                    </template>
                                    <template x-if="!activeTarget?.nation?.id">
                                        <span x-text="`— Nation #${activeTarget?.nation_id ?? ''}`"></span>
                                    </template>
                                </h3>
                                <p class="text-sm text-base-content/60">Assign in-range friendlies or add one manually by nation ID.</p>
                            </div>
                            <form method="dialog">
                                <button class="btn btn-ghost btn-sm btn-circle" aria-label="Close">✕</button>
                            </form>
                        </div>

                        <div class="space-y-4">
                                <h6 class="font-semibold">In-range friendlies</h6>
                                <p class="text-sm text-base-content/60">All nations in war range, sorted with recommended options first.</p>
                                <div class="overflow-x-auto rounded-box border border-base-300">
                                    <table class="table table-zebra table-sm">
                                        <thead>
                                        <tr>
                                            <th>Friendly</th>
                                            <th>Alliance</th>
                                            <th>Strength</th>
                                            <th>Wars</th>
                                            <th>Match score</th>
                                            <th>Assignments</th>
                                            <th>Open slots</th>
                                            <th class="text-right">Action</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <template x-if="candidatesLoading">
                                            <tr>
                                                <td colspan="8" class="text-center text-base-content/50 py-3">Loading candidates…</td>
                                            </tr>
                                        </template>
                                        <template x-if="!candidatesLoading && candidatesError">
                                            <tr>
                                                <td colspan="8" class="py-3 text-center text-error" x-text="candidatesError"></td>
                                            </tr>
                                        </template>
                                        <template x-if="!candidatesLoading && !candidatesError && !candidatesForActiveTarget().length">
                                            <tr>
                                                <td colspan="8" class="text-center text-base-content/50 py-3">No friendlies are in war range right now.</td>
                                            </tr>
                                        </template>
                                        <template x-for="candidate in (candidatesLoading ? [] : candidatesForActiveTarget())" :key="candidate.friendly.id">
                                            <tr>
                                                <td>
                                                    <a :href="`https://politicsandwar.com/nation/id=${candidate.friendly.id}`" target="_blank" rel="noopener noreferrer" class="font-semibold" x-text="candidate.friendly.leader_name"></a>
                                                    <div class="text-sm text-base-content/60" x-text="candidate.friendly.nation_name"></div>
                                                </td>
                                                <td>
                                                    <template x-if="candidate.friendly.alliance">
                                                        <div>
                                                            <a :href="`https://politicsandwar.com/alliance/id=${candidate.friendly.alliance.id}`" target="_blank" x-text="candidate.friendly.alliance.name"></a>
                                                            <div class="text-sm text-base-content/60" x-text="candidate.friendly.alliance.acronym"></div>
                                                        </div>
                                                    </template>
                                                    <template x-if="!candidate.friendly.alliance">
                                                        <span class="text-base-content/50">No alliance</span>
                                                    </template>
                                                </td>
                                                <td>
                                                    <div class="text-sm">
                                                        Score <span x-text="formatNumber(candidate.friendly.score, 2)"></span>
                                                        • Cities <span x-text="candidate.friendly.num_cities ?? 0"></span>
                                                    </div>
                                                    <div class="text-sm text-base-content/60">
                                                        Soldiers <span x-text="formatNumber(candidate.friendly.military?.soldiers || 0)"></span>
                                                        • Tanks <span x-text="formatNumber(candidate.friendly.military?.tanks || 0)"></span>
                                                    </div>
                                                    <div class="text-sm text-base-content/60">
                                                        Aircraft <span x-text="formatNumber(candidate.friendly.military?.aircraft || 0)"></span>
                                                        • Ships <span x-text="formatNumber(candidate.friendly.military?.ships || 0)"></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-ghost" title="Offensive / defensive wars">
                                                        <span x-text="candidate.friendly.offensive_wars_count ?? 0"></span>
                                                        /
                                                        <span x-text="candidate.friendly.defensive_wars_count ?? 0"></span>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="flex items-center gap-2">
                                                        <span class="badge badge-info" x-text="formatNumber(candidate.score, 1)"></span>
                                                        <span class="badge"
                                                              :class="candidate.recommended ? 'badge-success' : 'badge-ghost'"
                                                              x-text="candidate.recommended ? 'Recommended' : 'Manual only'"></span>
                                                    </div>
                                                </td>
                                                <td x-text="`${candidate.assignment_load} / ${candidate.max_assignments}`"></td>
                                                <td x-text="candidate.available_slots"></td>
                                                <td class="text-right">
                                                    <form method="post" :action="routes.manualAssignment">
                                                        @csrf
                                                        <input type="hidden" name="war_plan_target_id" :value="activeTarget?.id">
                                                        <input type="hidden" name="friendly_nation_id" :value="candidate.friendly.id">
                                                        <input type="hidden" name="match_score" :value="candidate.score">
                                                        <button type="submit" class="btn btn-outline btn-primary btn-sm">Assign</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        </template>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="border-t border-base-300"></div>
                                <h6 class="font-semibold">Manual assignment</h6>
                                <form method="post" :action="routes.manualAssignment" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_10rem]">
                                    @csrf
                                    <input type="hidden" name="war_plan_target_id" :value="activeTarget?.id">
                                    <label class="block space-y-2">
                                        <span class="text-sm font-medium">Friendly nation ID</span>
                                        <input type="number" name="friendly_nation_id" class="input input-bordered w-full"
                                               list="friendly-options-{{ $plan->id }}" placeholder="Type ID or select" required>
                                    </label>
                                    <label class="block space-y-2">
                                        <span class="text-sm font-medium">Match score</span>
                                        <input type="number" name="match_score" class="input input-bordered w-full" min="0" max="100" placeholder="50">
                                    </label>
                                    <div class="flex items-end">
                                        <button type="submit" class="btn btn-primary w-full">Assign</button>
                                    </div>
                                </form>
                                <p class="mb-0 text-sm text-base-content/60">Use the datalist to search by leader name if you already know the responder.</p>
                        </div>
                    </div>
                    <form method="dialog" class="modal-backdrop">
                        <button>close</button>
                    </form>
                </dialog>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm" x-data="assignmentsTable()">
                <div class="border-b border-base-300 px-6 py-4">
                    <div class="flex flex-wrap justify-content-between items-center gap-2">
                        <div>
                            <h5 class="card-title mb-0">Assignments &amp; squads</h5>
                            <small class="text-base-content/50">Full overview of friendlies per target. Max six offensive slots, three defensive.</small>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.war-plans.assignments.export-csv', $plan) }}" class="btn btn-outline btn-success btn-sm">
                                <i class="o-arrow-down-tray mr-1"></i> Export CSV
                            </a>
                            <button type="button" class="btn btn-outline btn-sm" @click="fetchAssignments">
                                <i class="o-arrow-path mr-1"></i> Refresh
                            </button>
                            <div class="items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                                <div class="loading loading-spinner loading-sm text-primary" role="status">
                                    
                                </div>
                                <span class="text-base-content/50 small">Loading assignments…</span>
                            </div>
                            <span class="text-sm text-error" x-show="error" x-text="error"></span>
                            <button class="btn btn-outline btn-sm" type="button" x-show="error" @click="fetchAssignments">Retry</button>
                        </div>
                    </div>
                </div>
                <div class="p-0">
                    <div class="p-3 flex flex-wrap gap-2 items-center">
                        <div class="flex w-full max-w-sm items-center gap-2">
                            <span class="text-sm font-medium text-base-content/70">Search</span>
                            <input type="search" class="input input-bordered w-full" placeholder="Friendly, target, status" x-model.debounce.300ms="search">
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-box border border-base-300" x-show="!loading" x-cloak style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="bg-base-200 sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Target</th>
                                <th>Squad</th>
                                <th>Friendly</th>
                                <th>Alliance</th>
                                <th>Wars</th>
                                <th>Assignments</th>
                                <th>Match score</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredAssignments.length && !error">
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-base-content/50">No assignments yet. Auto-generate or use the Assign buttons to attach friendlies.</td>
                                </tr>
                            </template>
                            <template x-for="assignment in filteredAssignments" :key="assignment.id">
                                <tr>
                                    <td>
                                        <template x-if="assignment.target?.nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${assignment.target.nation.id}`" target="_blank" class="font-semibold" x-text="assignment.target.nation.leader_name"></a>
                                                <div class="small text-base-content/50" x-text="assignment.target.nation.nation_name"></div>
                                                <div class="small">
                                                    Score <span x-text="formatNumber(assignment.target.nation.score, 2)"></span>
                                                    • Cities <span x-text="assignment.target.nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="small text-base-content/50">
                                                    Soldiers <span x-text="formatNumber(assignment.target.nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(assignment.target.nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="small text-base-content/50">
                                                    Aircraft <span x-text="formatNumber(assignment.target.nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(assignment.target.nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.target?.nation">
                                            <span class="text-base-content/50">Unknown</span>
                                        </template>
                                    </td>
                                    <td x-text="assignment.squad?.label ?? 'Unassigned'"></td>
                                    <td>
                                        <template x-if="assignment.friendly_nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${assignment.friendly_nation.id}`" target="_blank" class="font-semibold" x-text="assignment.friendly_nation.leader_name"></a>
                                                <div class="small text-base-content/50" x-text="assignment.friendly_nation.nation_name"></div>
                                                <div class="small">
                                                    Score <span x-text="formatNumber(assignment.friendly_nation.score, 2)"></span>
                                                    • Cities <span x-text="assignment.friendly_nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="small text-base-content/50">
                                                    Soldiers <span x-text="formatNumber(assignment.friendly_nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(assignment.friendly_nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="small text-base-content/50">
                                                    Aircraft <span x-text="formatNumber(assignment.friendly_nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(assignment.friendly_nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.friendly_nation">
                                            <span class="text-base-content/50">Unknown</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="assignment.friendly_nation?.alliance">
                                            <div>
                                                <a :href="`https://politicsandwar.com/alliance/id=${assignment.friendly_nation.alliance.id}`" target="_blank" x-text="assignment.friendly_nation.alliance.name"></a>
                                                <div class="small text-base-content/50" x-text="assignment.friendly_nation.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.friendly_nation?.alliance">
                                            <span class="text-base-content/50">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost" title="Offensive / defensive wars currently active">
                                            <span x-text="assignment.friendly_nation?.offensive_wars_count ?? 0"></span>
                                            /
                                            <span x-text="assignment.friendly_nation?.defensive_wars_count ?? 0"></span>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost" title="Assigned targets vs capacity"
                                              x-text="assignmentCapacity(assignment)">
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2 mb-1">
                                            <div class="progress flex-grow-1" style="height: 8px;" aria-label="Match strength">
                                                <div class="progress-bar bg-info" role="progressbar" :style="`width: ${Math.min(100, assignment.match_score ?? 0)}%`"
                                                     :aria-valuenow="assignment.match_score ?? 0" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <span class="badge badge-info" x-text="formatNumber(assignment.match_score, 1)"></span>
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <button class="btn btn-outline btn-sm" type="button" @click="toggleAssignmentMeta(assignment.id)">
                                                Details
                                            </button>
                                            <span class="badge badge-ghost" title="Manual override" x-show="assignment.is_overridden">Manual</span>
                                            <span class="badge badge-success" title="Locked assignment" x-show="assignment.is_locked">Locked</span>
                                        </div>
                                        <div class="mt-2" x-show="isAssignmentMetaOpen(assignment.id)" x-transition>
                                            <pre class="mb-0 small text-base-content/50" x-text="prettyMeta(assignment.meta)"></pre>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <span class="badge badge-ghost text-uppercase" x-text="assignment.status"></span>
                                            <span class="badge badge-ghost" title="Friendly on beige" x-show="(assignment.friendly_nation?.beige_turns ?? 0) > 0">Beige</span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <form method="post" :action="routes.deleteAssignment(assignment.id)"
                                              onsubmit="return confirm('Remove this assignment?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline btn-error btn-sm" type="submit"><i class="o-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header flex justify-content-between items-center">
                    <h5 class="card-title mb-0">Live attacks</h5>
                    <small class="text-base-content/50">Filter window, scope, or attack type to focus the feed.</small>
                </div>
                <div class="p-6">
                    <form class="row g-3 mb-3" method="get">
                        <div class="col-md-3">
                            <label class="form-label">Minutes</label>
                            <input type="number" class="input input-bordered w-full" name="minutes" value="{{ request('minutes') }}"
                                   placeholder="{{ config('war.live_feed.default_window_minutes') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Scope</label>
                            <select name="scope" class="select select-bordered w-full">
                                <option value="both" @selected(request('scope') === 'both')>All</option>
                                <option value="ours" @selected(request('scope') === 'ours')>Friendlies</option>
                                <option value="theirs" @selected(request('scope') === 'theirs')>Enemies</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Attack types (CSV)</label>
                            <input type="text" class="input input-bordered w-full" name="attack_types"
                                   value="{{ is_array(request('attack_types')) ? implode(',', request('attack_types')) : request('attack_types') }}"
                                   placeholder="ground,air">
                        </div>
                        <div class="col-md-2 flex items-end">
                            <button class="btn btn-outline btn-primary w-full" type="submit">Apply</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto rounded-box border border-base-300" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="bg-base-200 sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Time</th>
                                <th>Attacker</th>
                                <th>Defender</th>
                                <th>Type</th>
                                <th>Result</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($liveFeed as $attack)
                                <tr>
                                    <td>{{ optional($attack->date)->diffForHumans() }}</td>
                                    <td>
                                        @if($attack->attacker?->id)
                                            <a href="https://politicsandwar.com/nation/id={{ $attack->attacker->id }}" target="_blank" rel="noopener noreferrer">{{ $attack->attacker->leader_name ?? $attack->att_id }}</a>
                                        @else
                                            {{ $attack->att_id }}
                                        @endif
                                    </td>
                                    <td>
                                        @if($attack->defender?->id)
                                            <a href="https://politicsandwar.com/nation/id={{ $attack->defender->id }}" target="_blank" rel="noopener noreferrer">{{ $attack->defender->leader_name ?? $attack->def_id }}</a>
                                        @else
                                            {{ $attack->def_id }}
                                        @endif
                                    </td>
                                    <td>{{ $attack->type?->name ?? $attack->type }}</td>
                                    <td>{{ $attack->victor ?? 'Inconclusive' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-base-content/50">No attacks within the selected window.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header flex flex-column flex-lg-row align-items-lg-center gap-3">
                    <div>
                        <h5 class="card-title mb-0">Comparative Stats</h5>
                        <small class="text-base-content/50">Quick glance at friendly vs enemy scale and militarization.</small>
                    </div>
                </div>
                <div class="p-6">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="font-semibold flex justify-content-between">
                                <span>Cities</span>
                                <span class="text-base-content/50 small">Friendly {{ number_format($friendlyCityTotal) }} / Enemy {{ number_format($enemyCityTotal) }}</span>
                            </h6>
                            <div class="progress" style="height: 10px;" aria-label="Cities share">
                                @php
                                    $cityTotal = max(1, $friendlyCityTotal + $enemyCityTotal);
                                    $friendlyCityPct = round(($friendlyCityTotal / $cityTotal) * 100, 1);
                                @endphp
                                <div class="progress-bar bg-primary" style="width: {{ $friendlyCityPct }}%" title="Friendly avg {{ number_format($friendlyCityAvg ?? 0, 1) }} | Enemy avg {{ number_format($enemyCityAvg ?? 0, 1) }}"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="font-semibold flex justify-content-between">
                                <span>Force Readiness</span>
                                <span class="text-base-content/50 small">By unit type</span>
                            </h6>
                            @foreach (['soldiers' => 'Soldiers', 'tanks' => 'Tanks', 'aircraft' => 'Aircraft', 'ships' => 'Ships'] as $unitKey => $label)
                                @php
                                    $friendlyVal = $friendlyMilTotals[$unitKey] ?? 0;
                                    $enemyVal = $enemyMilTotals[$unitKey] ?? 0;
                                    $totalUnits = $friendlyVal + $enemyVal;
                                    if ($totalUnits <= 0) {
                                        $friendlyPct = 50;
                                        $enemyPct = 50;
                                    } else {
                                        $friendlyPct = round(($friendlyVal / $totalUnits) * 100, 1);
                                        $enemyPct = round(100 - $friendlyPct, 1);
                                    }
                                @endphp
                                <div class="mb-2">
                                    <div class="flex justify-content-between small">
                                        <span>{{ $label }}</span>
                                        <span class="text-base-content/50">Friendly {{ number_format($friendlyVal) }} • Enemy {{ number_format($enemyVal) }}</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-primary" style="width: {{ $friendlyPct }}%" title="Friendly {{ number_format($friendlyVal) }} units ({{ $friendlyPct }}% share)"></div>
                                        <div class="progress-bar bg-danger" style="width: {{ $enemyPct }}%" title="Enemy {{ number_format($enemyVal) }} units ({{ $enemyPct }}% share)"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="col-md-6">
                            <h6 class="font-semibold">Average Cities</h6>
                            <p class="mb-1"><span class="badge badge-primary">Friendly {{ number_format($friendlyCityAvg ?? 0, 1) }}</span></p>
                            <p class="mb-1"><span class="badge badge-error">Enemy {{ number_format($enemyCityAvg ?? 0, 1) }}</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="font-semibold">Assignments Coverage</h6>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: {{ $coverage ?? 0 }}%" title="Assignments {{ $assignmentCount }} / Desired {{ $preferredSlotsTotal ?? 0 }}"></div>
                            </div>
                            <small class="text-base-content/50">
                                Remaining gap: {{ $preferredSlotsTotal > 0 ? max(0, $preferredSlotsTotal - $assignmentCount).' slots' : 'n/a' }}.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm h-100" x-data="friendliesPanel()">
                <div class="card-header flex justify-content-between items-center">
                    <div>
                        <h5 class="card-title mb-0">Unassigned friendlies</h5>
                        <small class="text-base-content/50">No current target — fill gaps manually if needed.</small>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn btn-outline btn-sm" @click="fetchFriendlies">
                            <i class="o-arrow-path mr-1"></i> Refresh
                        </button>
                        <div class="items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                            <div class="loading loading-spinner loading-sm text-primary" role="status">
                                
                            </div>
                            <span class="text-base-content/50 small">Loading friendlies…</span>
                        </div>
                        <span class="text-sm text-error" x-show="error" x-text="error"></span>
                        <button class="btn btn-outline btn-sm" type="button" x-show="error" @click="fetchFriendlies">Retry</button>
                    </div>
                </div>
                <div class="p-0">
                    <div class="p-3 flex flex-wrap gap-2 items-center">
                        <div class="flex w-full max-w-sm items-center gap-2">
                            <span class="text-sm font-medium text-base-content/70">Search</span>
                            <input type="search" class="input input-bordered w-full" placeholder="Friendly, alliance" x-model.debounce.300ms="search">
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-box border border-base-300" id="assignments-table" x-show="!loading" x-cloak style="max-height: 520px; overflow-y: auto;">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="bg-base-200 sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Nation</th>
                                <th>Alliance</th>
                                <th>Wars</th>
                                <th>Activity</th>
                                <th class="text-right">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredUnassigned.length && !error">
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-base-content/50">Everyone is assigned.</td>
                                </tr>
                            </template>
                            <template x-for="friendly in filteredUnassigned" :key="friendly.id">
                                <tr>
                                    <td>
                                        <a :href="`https://politicsandwar.com/nation/id=${friendly.id}`" target="_blank" class="font-semibold" x-text="friendly.leader_name"></a>
                                        <div class="small text-base-content/50" x-text="friendly.nation_name"></div>
                                        <div class="small">
                                            Cities <span x-text="friendly.num_cities ?? 0"></span>
                                            • Score <span x-text="formatNumber(friendly.score, 2)"></span>
                                        </div>
                                    </td>
                                    <td>
                                        <template x-if="friendly.alliance">
                                            <div>
                                                <span class="inline-flex items-center gap-1">
                                                    <i class="o-users text-base-content/50"></i>
                                                    <a :href="`https://politicsandwar.com/alliance/id=${friendly.alliance.id}`" target="_blank" x-text="friendly.alliance.name"></a>
                                                </span>
                                                <div class="small text-base-content/50" x-text="friendly.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!friendly.alliance">
                                            <span class="text-base-content/50">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost" title="Offensive / defensive">
                                            <span x-text="friendly.offensive_wars_count ?? 0"></span>
                                            /
                                            <span x-text="friendly.defensive_wars_count ?? 0"></span>
                                        </span>
                                    </td>
                                    <td x-text="relativeTime(friendly.account_profile?.last_active)"></td>
                                    <td class="text-right">
                                        <button class="btn btn-outline btn-primary btn-sm"
                                                @click="$store.warPlan.setQuickAssign(friendly); $nextTick(() => document.getElementById('quickAssignModal').showModal())">
                                            Assign
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            @include('admin.war-room.partials.score-guide')
        </div>
    </div>

    <dialog class="modal" id="importPlanModal">
        <div class="modal-box max-w-3xl">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold">Import plan JSON</h3>
                    <p class="text-sm text-base-content/60">Paste an exported payload to merge or replace current plan data.</p>
                </div>
                <form method="dialog">
                    <button class="btn btn-ghost btn-sm btn-circle" aria-label="Close">✕</button>
                </form>
            </div>

            <form method="post" action="{{ route('admin.war-plans.import', $plan) }}" class="space-y-4">
                @csrf

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Payload</span>
                    <textarea class="textarea textarea-bordered min-h-56 w-full" name="payload" rows="10" placeholder="Paste exported JSON here" required></textarea>
                </label>

                <label class="label cursor-pointer justify-start gap-3">
                    <input class="checkbox checkbox-sm" type="checkbox" name="dry_run" value="1" id="dryRun">
                    <span class="label-text">Dry run (preview diff only)</span>
                </label>

                <p class="mb-0 text-sm text-base-content/60">Imports honor the latest schema and override conflicting targets or assignments.</p>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('importPlanModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Process import</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    @stack('modals')
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script>
        window.warPlanConfig = {
            planId: {{ $plan->id }},
            warTypes: @json($warTypes),
            preferredAssignmentsPerTarget: {{ $preferredAssignmentsPerTarget }},
            routes: {
                targets: "{{ route('api.admin.war-plans.targets', $plan) }}",
                targetCandidates: "{{ route('api.admin.war-plans.target-candidates', [$plan, '__TARGET__']) }}",
                assignments: "{{ route('api.admin.war-plans.assignments', $plan) }}",
                friendlies: "{{ route('api.admin.war-plans.friendlies', $plan) }}",
                updateTargetWarType: "{{ route('admin.war-plans.targets.update-war-type', [$plan, '__TARGET__']) }}",
                deleteTarget: "{{ route('admin.war-plans.targets.destroy', [$plan, '__TARGET__']) }}",
                manualAssignment: "{{ route('admin.war-plans.assignments.manual', $plan) }}",
                deleteAssignment: "{{ route('admin.war-plans.assignments.destroy', [$plan, '__ASSIGNMENT__']) }}",
            },
        };

        function refreshTooltips() {        }

        function formatNumber(value, decimals = 0) {
            const number = Number(value ?? 0);
            return Number.isFinite(number) ? number.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) : '0';
        }

        async function httpGet(url) {
            if (window.axios) {
                const {data} = await window.axios.get(url);
                return data;
            }

            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return await response.json();
        }

        function prettyMeta(meta) {
            if (!meta) {
                return 'No metadata';
            }

            try {
                return JSON.stringify(meta, null, 2);
            } catch (e) {
                return 'Unable to render metadata';
            }
        }

        function fuzzyMatch(haystack, needle) {
            if (!needle) {
                return true;
            }

            const normalizedHaystack = (haystack || '').toString().toLowerCase();
            const normalizedNeedle = needle.toString().toLowerCase();

            let position = 0;

            for (const char of normalizedNeedle) {
                position = normalizedHaystack.indexOf(char, position);
                if (position === -1) {
                    return false;
                }
                position++;
            }

            return true;
        }

        function relativeTime(dateString) {
            if (!dateString) {
                return 'Unknown';
            }

            const date = new Date(dateString);

            if (Number.isNaN(date.getTime())) {
                return dateString;
            }

            const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
            const intervals = [
                {label: 'year', seconds: 31536000},
                {label: 'month', seconds: 2592000},
                {label: 'day', seconds: 86400},
                {label: 'hour', seconds: 3600},
                {label: 'minute', seconds: 60},
            ];

            for (const interval of intervals) {
                const count = Math.floor(seconds / interval.seconds);
                if (count >= 1) {
                    return `${count} ${interval.label}${count > 1 ? 's' : ''} ago`;
                }
            }

            return 'Just now';
        }

        document.addEventListener('alpine:init', () => {
            const config = window.warPlanConfig;

            Alpine.store('warPlan', {
                planId: config.planId,
                warTypes: config.warTypes,
                preferredAssignmentsPerTarget: config.preferredAssignmentsPerTarget,
                routes: {
                    targets: config.routes.targets,
                    targetCandidates: (targetId) => config.routes.targetCandidates.replace('__TARGET__', targetId),
                    assignments: config.routes.assignments,
                    friendlies: config.routes.friendlies,
                    updateTargetWarType: (targetId) => config.routes.updateTargetWarType.replace('__TARGET__', targetId),
                    deleteTarget: (targetId) => config.routes.deleteTarget.replace('__TARGET__', targetId),
                    manualAssignment: config.routes.manualAssignment,
                    deleteAssignment: (assignmentId) => config.routes.deleteAssignment.replace('__ASSIGNMENT__', assignmentId),
                },
                metaOpen: {},
                targets: [],
                friendlies: [],
                friendlyStats: {},
                unassigned: [],
                candidateMap: {},
                quickAssign: null,
                setTargets(targets) {
                    this.targets = targets || [];
                },
                setFriendlies(friendlies) {
                    this.friendlies = friendlies || [];
                },
                setFriendlyStats(stats) {
                    const map = {};
                    (stats || []).forEach((stat) => {
                        map[stat.friendly_nation_id] = stat;
                    });
                    this.friendlyStats = map;
                },
                setUnassigned(unassigned) {
                    this.unassigned = unassigned || [];
                },
                setCandidateMap(map) {
                    this.candidateMap = map || {};
                },
                setQuickAssign(friendly) {
                    this.quickAssign = friendly || null;
                },
                toggleMeta(key) {
                    this.metaOpen[key] = !this.metaOpen[key];
                },
                isMetaOpen(key) {
                    return this.metaOpen[key] ?? false;
                },
                targetAvailable(target) {
                    if (!target?.nation || (target.nation.vacation_mode_turns ?? 0) > 0) {
                        return false;
                    }

                    const assigned = target.assignments_count ?? 0;
                    return (this.preferredAssignmentsPerTarget ?? 0) === 0 || assigned < this.preferredAssignmentsPerTarget;
                },
            });
        });

        function targetsTable() {
            return {
                loading: true,
                error: null,
                search: '',
                targets: [],
                activeTarget: null,
                candidatesLoading: false,
                candidatesError: null,
                expandedTargets: {},
                init() {
                    this.fetchTargets();
                },
                get warTypes() {
                    return Alpine.store('warPlan').warTypes || {};
                },
                get routes() {
                    return Alpine.store('warPlan').routes;
                },
                async fetchTargets() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await httpGet(this.routes.targets);
                        this.targets = data.targets || [];
                        Alpine.store('warPlan').setTargets(this.targets);
                        Alpine.store('warPlan').preferredAssignmentsPerTarget = data.preferred_assignments_per_target ?? Alpine.store('warPlan').preferredAssignmentsPerTarget;
                        Alpine.store('warPlan').setCandidateMap({});
                        this.$nextTick(refreshTooltips);
                    } catch (e) {
                        this.error = 'Unable to load targets.';
                    } finally {
                        this.loading = false;
                    }
                },
                get filteredTargets() {
                    const query = this.search.trim();

                    if (!query) {
                        return this.targets;
                    }

                    return this.targets.filter((target) => fuzzyMatch(this.buildSearchBlob(target), query));
                },
                buildSearchBlob(target) {
                    const parts = [];

                    if (target?.nation) {
                        parts.push(
                            target.nation.leader_name,
                            target.nation.nation_name,
                            target.nation.alliance?.name,
                            target.nation.alliance?.acronym
                        );
                    }

                    parts.push(target.target_priority_score ?? '', target.preferred_war_type ?? '');

                    return parts.join(' ').toLowerCase();
                },
                slotsFor(target) {
                    const preferred = Alpine.store('warPlan').preferredAssignmentsPerTarget ?? 1;

                    return Math.max(1, preferred);
                },
                badgeForSlots(target) {
                    return (target.assignments_count ?? 0) >= this.slotsFor(target) ? 'badge-error' : 'badge-success';
                },
                isVacation(target) {
                    return (target.nation?.vacation_mode_turns ?? 0) > 0;
                },
                lastActive(target) {
                    return relativeTime(target.nation?.account_profile?.last_active);
                },
                toggleTargetMeta(targetId) {
                    this.expandedTargets[targetId] = !this.expandedTargets[targetId];
                },
                isTargetMetaOpen(targetId) {
                    return !!this.expandedTargets[targetId];
                },
                setActiveTarget(target) {
                    this.activeTarget = target;
                    this.fetchCandidatesForTarget(target?.id);
                },
                candidatesForActiveTarget() {
                    if (!this.activeTarget) {
                        return [];
                    }

                    return Alpine.store('warPlan').candidateMap?.[this.activeTarget.id] || [];
                },
                async fetchCandidatesForTarget(targetId) {
                    if (!targetId) {
                        return;
                    }

                    const existing = Alpine.store('warPlan').candidateMap?.[targetId];
                    if (Array.isArray(existing)) {
                        this.candidatesError = null;
                        return;
                    }

                    this.candidatesLoading = true;
                    this.candidatesError = null;

                    try {
                        const data = await httpGet(this.routes.targetCandidates(targetId));
                        const candidateMap = {...(Alpine.store('warPlan').candidateMap || {})};
                        candidateMap[targetId] = data.candidates || [];
                        Alpine.store('warPlan').setCandidateMap(candidateMap);
                    } catch (e) {
                        this.candidatesError = 'Unable to load target candidates.';
                    } finally {
                        this.candidatesLoading = false;
                    }
                },
                formatNumber,
                prettyMeta,
            };
        }

        function assignmentsTable() {
            return {
                loading: true,
                error: null,
                search: '',
                assignments: [],
                expandedAssignments: {},
                init() {
                    this.fetchAssignments();
                },
                get routes() {
                    return Alpine.store('warPlan').routes;
                },
                async fetchAssignments() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await httpGet(this.routes.assignments);
                        const assignments = data.assignments || [];

                        assignments.sort((a, b) => {
                            if (a.war_plan_target_id !== b.war_plan_target_id) {
                                return (a.war_plan_target_id ?? 0) - (b.war_plan_target_id ?? 0);
                            }

                            const squadA = a.squad?.label || 'ZZZ';
                            const squadB = b.squad?.label || 'ZZZ';

                            if (squadA !== squadB) {
                                return squadA.localeCompare(squadB);
                            }

                            return (b.match_score ?? 0) - (a.match_score ?? 0);
                        });

                        this.assignments = assignments;
                        this.$nextTick(refreshTooltips);
                    } catch (e) {
                        this.error = 'Unable to load assignments.';
                    } finally {
                        this.loading = false;
                    }
                },
                get filteredAssignments() {
                    const query = this.search.trim();

                    if (!query) {
                        return this.assignments;
                    }

                    return this.assignments.filter((assignment) => fuzzyMatch(this.buildSearchBlob(assignment), query));
                },
                buildSearchBlob(assignment) {
                    const parts = [];
                    const friendly = assignment.friendly_nation;
                    const target = assignment.target?.nation;

                    if (friendly) {
                        parts.push(friendly.leader_name, friendly.nation_name, friendly.alliance?.name, friendly.alliance?.acronym);
                    }

                    if (target) {
                        parts.push(target.leader_name, target.nation_name, target.alliance?.name);
                    }

                    parts.push(assignment.status ?? '');

                    return parts.join(' ').toLowerCase();
                },
                assignmentCapacity(assignment) {
                    const stats = Alpine.store('warPlan').friendlyStats?.[assignment.friendly_nation_id] || {};
                    const load = stats.assignment_load ?? 0;
                    const max = stats.max_assignments ?? Math.max(1, Alpine.store('warPlan').preferredAssignmentsPerTarget ?? 1);

                    return `${load} / ${max}`;
                },
                toggleAssignmentMeta(assignmentId) {
                    this.expandedAssignments[assignmentId] = !this.expandedAssignments[assignmentId];
                },
                isAssignmentMetaOpen(assignmentId) {
                    return !!this.expandedAssignments[assignmentId];
                },
                formatNumber,
                prettyMeta,
            };
        }

        function friendliesPanel() {
            return {
                loading: true,
                error: null,
                search: '',
                init() {
                    this.fetchFriendlies();
                },
                get routes() {
                    return Alpine.store('warPlan').routes;
                },
                async fetchFriendlies() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const data = await httpGet(this.routes.friendlies);
                        const friendlies = (data.friendlies || []).sort((a, b) => (a.leader_name || '').localeCompare(b.leader_name || ''));
                        const unassigned = (data.unassigned || []).sort((a, b) => (a.leader_name || '').localeCompare(b.leader_name || ''));

                        Alpine.store('warPlan').setFriendlies(friendlies);
                        Alpine.store('warPlan').setFriendlyStats(data.friendly_stats || []);
                        Alpine.store('warPlan').setUnassigned(unassigned);
                        this.$nextTick(refreshTooltips);
                    } catch (e) {
                        this.error = 'Unable to load friendlies.';
                    } finally {
                        this.loading = false;
                    }
                },
                get filteredUnassigned() {
                    const query = this.search.trim();
                    const unassigned = Alpine.store('warPlan').unassigned || [];

                    if (!query) {
                        return unassigned;
                    }

                    return unassigned.filter((friendly) => fuzzyMatch(this.buildSearchBlob(friendly), query));
                },
                buildSearchBlob(friendly) {
                    return [
                        friendly.leader_name,
                        friendly.nation_name,
                        friendly.alliance?.name,
                        friendly.alliance?.acronym,
                    ].join(' ').toLowerCase();
                },
                formatNumber,
            };
        }

        document.addEventListener('codex:page-ready', () => {
            refreshTooltips();
        });
    </script>
@endpush
