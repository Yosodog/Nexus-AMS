@extends('layouts.admin')

@section('content')
    <x-header title="War Room Dashboard" separator>
        <x-slot:subtitle>Track reactive counters alongside proactive war plans.</x-slot:subtitle>
        <x-slot:actions>
            <button class="btn btn-primary btn-sm" type="button" onclick="document.getElementById('createWarPlanModal').showModal()">
                <x-icon name="o-plus-circle" class="size-4" />
                Create War Plan
            </button>
        </x-slot:actions>
    </x-header>

    <div class="space-y-6">
        <x-card title="Counters">
            <x-slot:menu>
                <form class="flex flex-wrap justify-end gap-2" method="get">
                    <input type="hidden" name="plan_search" value="{{ $planSearch }}">
                    <input type="hidden" name="plan_status" value="{{ $planStatus }}">

                    <input
                        type="text"
                        class="input input-bordered input-sm w-full sm:w-52"
                        name="counter_active_search"
                        value="{{ $counterSearch }}"
                        placeholder="Search aggressor"
                    >

                    <select name="counter_status" class="select select-bordered select-sm w-full sm:w-40">
                        <option value="all" @selected($counterStatus === 'all')>All statuses</option>
                        <option value="active" @selected($counterStatus === 'active')>Active</option>
                        <option value="draft" @selected($counterStatus === 'draft')>Draft</option>
                    </select>

                    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
                </form>
            </x-slot:menu>

            <div class="mb-4 text-sm text-base-content/60">Live and planning counters sharing assignment pools.</div>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>Aggressor</th>
                        <th>Alliance</th>
                        <th>Status</th>
                        <th>War Type</th>
                        <th>Team Size</th>
                        <th>Last Update</th>
                        <th class="text-right">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($counters as $counter)
                        <tr>
                            <td>
                                @if($counter->aggressor?->id)
                                    <a href="https://politicsandwar.com/nation/id={{ $counter->aggressor->id }}" target="_blank" rel="noopener noreferrer" class="font-semibold link link-hover">
                                        {{ $counter->aggressor->leader_name ?? 'Unknown' }}
                                    </a>
                                @else
                                    <span class="font-semibold">{{ $counter->aggressor->leader_name ?? 'Unknown' }}</span>
                                @endif
                                <div class="text-sm text-base-content/60">{{ $counter->aggressor->nation_name ?? '—' }}</div>
                            </td>
                            <td>{{ $counter->aggressor->alliance->name ?? 'No Alliance' }}</td>
                            <td>
                                <span class="badge {{ $counter->status === 'active' ? 'badge-success' : 'badge-warning' }}">
                                    {{ strtoupper($counter->status) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-ghost">
                                    {{ config('war.war_types')[strtolower($counter->war_declaration_type ?? '')] ?? ucfirst($counter->war_declaration_type ?? 'Unknown') }}
                                </span>
                            </td>
                            <td><span class="badge badge-primary">{{ $counter->team_size }}</span></td>
                            <td>
                                <div>{{ optional($counter->updated_at)->diffForHumans() ?? '—' }}</div>
                                <div class="text-sm text-base-content/60">{{ optional($counter->updated_at)->toDayDateTimeString() ?? '' }}</div>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('admin.war-counters.show', $counter) }}" class="btn btn-outline btn-primary btn-sm">
                                    View Counter
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-base-content/60">No counters match the current filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($counters->hasPages())
                <div class="mt-4">{{ $counters->links() }}</div>
            @endif
        </x-card>

        <x-card title="War Plans">
            <x-slot:menu>
                <form class="flex flex-wrap justify-end gap-2" method="get">
                    <input type="hidden" name="counter_active_search" value="{{ $counterSearch }}">
                    <input type="hidden" name="counter_status" value="{{ $counterStatus }}">

                    <input
                        type="text"
                        class="input input-bordered input-sm w-full sm:w-52"
                        name="plan_search"
                        value="{{ $planSearch }}"
                        placeholder="Search plan"
                    >

                    <select name="plan_status" class="select select-bordered select-sm w-full sm:w-40">
                        <option value="all" @selected($planStatus === 'all')>All statuses</option>
                        <option value="active" @selected($planStatus === 'active')>Active</option>
                        <option value="planning" @selected($planStatus === 'planning')>Planning</option>
                    </select>

                    <button class="btn btn-outline btn-sm" type="submit">Apply</button>
                </form>
            </x-slot:menu>

            <div class="mb-4 text-sm text-base-content/60">Planning and active campaigns with assignment state.</div>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Default War Type</th>
                        <th>Targets</th>
                        <th>Assignments</th>
                        <th>Updated</th>
                        <th class="text-right">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($plans as $plan)
                        <tr>
                            <td class="font-semibold">{{ $plan->name }}</td>
                            <td>
                                <span class="badge {{ $plan->status === 'active' ? 'badge-success' : 'badge-warning' }}">
                                    {{ strtoupper($plan->status) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-info">
                                    {{ config('war.war_types')[strtolower($plan->plan_type ?? '')] ?? ucfirst($plan->plan_type ?? 'Unknown') }}
                                </span>
                            </td>
                            <td>{{ $plan->targets_count }}</td>
                            <td>{{ $plan->assignments_count }}</td>
                            <td>
                                <div>{{ optional($plan->updated_at)->diffForHumans() ?? '—' }}</div>
                                <div class="text-sm text-base-content/60">{{ optional($plan->updated_at)->toDayDateTimeString() ?? '' }}</div>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('admin.war-plans.show', $plan) }}" class="btn btn-outline btn-primary btn-sm">
                                    Open Plan
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-base-content/60">No war plans match the current filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($plans->hasPages())
                <div class="mt-4">{{ $plans->links() }}</div>
            @endif
        </x-card>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-card title="Discord War Alerts" subtitle="Channel ID used for {{ config('app.name') }} → Discord war alerts.">
                <form method="post" action="{{ route('admin.war-room.discord-channel') }}" class="space-y-4">
                    @csrf

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Channel ID</span>
                        <input
                            type="text"
                            id="channel_id"
                            name="channel_id"
                            class="input input-bordered w-full"
                            placeholder="e.g. 123456789012345678"
                            value="{{ old('channel_id', $discordWarChannelId) }}"
                        >
                        <span class="text-xs text-base-content/60">Leave blank to disable Discord war alerts.</span>
                    </label>

                    <label class="label cursor-pointer justify-start gap-3">
                        <input class="toggle toggle-primary" type="checkbox" id="enabled" name="enabled" value="1" {{ old('enabled', $discordWarAlertsEnabled) ? 'checked' : '' }}>
                        <span class="label-text">Enable war alert dispatches</span>
                    </label>

                    <div>
                        <button type="submit" class="btn btn-primary">Save Channel</button>
                    </div>
                </form>
            </x-card>

            <x-card title="Default War Room Forum" subtitle="Forum channel ID used when plans or counters do not override it.">
                <form method="post" action="{{ route('admin.war-room.default-forum') }}" class="space-y-4">
                    @csrf

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Forum Channel ID</span>
                        <input
                            type="text"
                            id="default_forum_channel_id"
                            name="default_forum_channel_id"
                            class="input input-bordered w-full"
                            placeholder="e.g. 123456789012345678"
                            value="{{ old('default_forum_channel_id', $defaultWarRoomForumId) }}"
                        >
                        <span class="text-xs text-base-content/60">Used for room creation when no override is set.</span>
                    </label>

                    <div>
                        <button type="submit" class="btn btn-primary">Save Forum</button>
                    </div>
                </form>
            </x-card>

            <x-card title="War Room Creation" subtitle="Controls automatic {{ config('app.name') }} counter room creation from incoming attacks.">
                <form method="post" action="{{ route('admin.war-room.creation') }}" class="space-y-4">
                    @csrf

                    <label class="label cursor-pointer justify-start gap-3">
                        <input class="toggle toggle-primary" type="checkbox" id="war_room_creation_enabled" name="enabled" value="1" {{ old('enabled', $warCounterAutoCreationEnabled) ? 'checked' : '' }}>
                        <span class="label-text">Enable automatic {{ config('app.name') }} counter room creation</span>
                    </label>

                    <div class="text-sm text-base-content/60">
                        War alerts still dispatch normally when this is disabled. Existing counter rooms can still create Discord rooms manually.
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary">Save Setting</button>
                    </div>
                </form>
            </x-card>

            <x-card title="Counter Defense Role" subtitle="Role ID pinged when a counter war room is created.">
                <form method="post" action="{{ route('admin.war-room.defense-role') }}" class="space-y-4">
                    @csrf

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Defense Role ID</span>
                        <input
                            type="text"
                            id="defense_role_id"
                            name="defense_role_id"
                            class="input input-bordered w-full"
                            placeholder="e.g. 123456789012345678"
                            value="{{ old('defense_role_id', $warRoomDefenseRoleId) }}"
                        >
                        <span class="text-xs text-base-content/60">Leave blank to skip role ping on counter room creation.</span>
                    </label>

                    <div>
                        <button type="submit" class="btn btn-primary">Save Role</button>
                    </div>
                </form>
            </x-card>
        </div>
    </div>

    <dialog id="createWarPlanModal" class="modal">
        <div class="modal-box max-w-4xl">
            <form method="post" action="{{ route('admin.war-plans.store') }}" class="space-y-4">
                @csrf

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">Create War Plan</h3>
                        <p class="text-sm text-base-content/60">Create a plan and tune the default targeting behavior.</p>
                    </div>
                    <button type="button" class="btn btn-circle btn-ghost btn-sm" onclick="document.getElementById('createWarPlanModal').close()">✕</button>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-sm font-medium">Plan Name</span>
                        <input type="text" class="input input-bordered w-full" name="name" maxlength="120" value="{{ old('name') }}" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Plan Type</span>
                        <select class="select select-bordered w-full" name="plan_type">
                            @foreach (config('war.war_types') as $value => $label)
                                <option value="{{ $value }}" @selected(old('plan_type', config('war.plan_defaults.plan_type', 'ordinary')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Activity Window (hours)</span>
                        <input type="number" class="input input-bordered w-full" name="activity_window_hours" min="12" max="240" value="{{ old('activity_window_hours', config('war.plan_defaults.activity_window_hours', 72)) }}">
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Preferred Targets per Nation</span>
                        <input type="number" class="input input-bordered w-full" name="preferred_targets_per_nation" min="1" max="6" value="{{ old('preferred_targets_per_nation', config('war.plan_defaults.preferred_targets_per_nation', 2)) }}">
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Max Squad Size</span>
                        <input type="number" class="input input-bordered w-full" name="max_squad_size" min="1" max="10" value="{{ old('max_squad_size', config('war.squads.max_size', 3)) }}">
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Squad Cohesion Tolerance (±)</span>
                        <input type="number" class="input input-bordered w-full" name="squad_cohesion_tolerance" min="1" max="50" value="{{ old('squad_cohesion_tolerance', config('war.squads.cohesion_tolerance', 10)) }}">
                    </label>

                    <label class="label cursor-pointer justify-start gap-3 md:col-span-2">
                        <input class="toggle toggle-primary" type="checkbox" name="suppress_counters_when_active" value="1" {{ old('suppress_counters_when_active', config('war.plan_defaults.suppress_counters_when_active', true)) ? 'checked' : '' }}>
                        <span class="label-text">Suppress counters while plan is active</span>
                    </label>

                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-sm font-medium">Friendly Alliance IDs (optional, comma separated)</span>
                        <input type="text" class="input input-bordered w-full" name="friendly_alliances_raw" placeholder="e.g. 123,456" value="{{ old('friendly_alliances_raw') }}">
                    </label>

                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-sm font-medium">Enemy Alliance IDs (optional, comma separated)</span>
                        <input type="text" class="input input-bordered w-full" name="enemy_alliances_raw" placeholder="e.g. 789,321" value="{{ old('enemy_alliances_raw') }}">
                    </label>
                </div>

                <p class="text-sm text-base-content/60">
                    You can adjust alliance membership, targets, and assignments inside the plan room after creation.
                </p>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('createWarPlanModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Plan</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
@endsection
