@extends('layouts.admin')

@section('content')
    <datalist id="friendly-options-{{ $plan->id }}" x-data>
        <template x-for="friendly in $store?.warPlan?.friendlies || []" :key="friendly.id">
            <option :value="friendly.id" x-text="`${friendly.leader_name} (${friendly.nation_name})`"></option>
        </template>
    </datalist>

    <x-header title="War Plan" separator use-h1>
        <x-slot:subtitle>
            {{ $plan->name }}
            <span class="mx-2 nexus-text-muted">•</span>
            <span
                class="badge badge-primary badge-sm uppercase tooltip tooltip-bottom cursor-help"
                data-tip="Plan type drives the default war declaration when our members engage targets."
                tabindex="0"
            >
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
                <div class="text-sm nexus-text-muted">Targets</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $enemyCount }}</span>
                    <span class="badge badge-ghost tooltip tooltip-left cursor-help" role="img" aria-label="Enemy nations tracked with Target Priority Scores" data-tip="Enemy nations tracked with Target Priority Scores" tabindex="0"><x-icon name="o-bolt" class="size-4" aria-hidden="true" /></span>
                </div>
            </div>
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm nexus-text-muted">Assign coverage</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $coverage !== null ? $coverage.'%' : 'n/a' }}</span>
                    <span class="badge badge-primary tooltip tooltip-left cursor-help" data-tip="Assignments / preferred slots" tabindex="0" aria-label="{{ $assignmentCount }} assignments out of {{ $preferredSlotsTotal }} preferred slots">{{ $assignmentCount }} / {{ $preferredSlotsTotal }}</span>
                </div>
            </div>
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm nexus-text-muted">Locked slots</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $lockedCount }}</span>
                    <span class="badge badge-success tooltip tooltip-left cursor-help" data-tip="Locked or overridden assignments remain untouched" tabindex="0">Safe</span>
                </div>
            </div>
            <div class="rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                <div class="text-sm nexus-text-muted">Preferred wars / nation</div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-2xl font-semibold">{{ $preferredTargetsPerNation }}</span>
                    <span class="badge badge-info tooltip tooltip-left cursor-help" data-tip="Activity window drives readiness weighting" tabindex="0" aria-label="{{ $plan->activity_window_hours }} hour activity window">{{ $plan->activity_window_hours }}h</span>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,0.9fr)]">
            <x-card title="Plan Options">
                <x-slot:menu>
                    <span class="tooltip tooltip-left cursor-help" role="img" aria-label="Baseline settings that influence automation heuristics" data-tip="Baseline settings that influence automation heuristics." tabindex="0"><x-icon name="o-question-mark-circle" class="size-4" aria-hidden="true" /></span>
                </x-slot:menu>

                <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-3 text-sm">
                    <div class="font-medium text-base-content/70">Plan Type</div>
                    <div class="text-right uppercase">{{ $warTypes[strtolower($plan->plan_type ?? '')] ?? ucfirst($plan->plan_type ?? 'Unknown') }}</div>

                    <div class="font-medium text-base-content/70 tooltip tooltip-bottom cursor-help" data-tip="Offensive wars we aim to give each friendly before applying slot caps." tabindex="0">Preferred targets / nation</div>
                    <div class="text-right">{{ $preferredTargetsPerNation }}</div>

                    <div class="font-medium text-base-content/70 tooltip tooltip-bottom cursor-help" data-tip="Upper bound when forming automatic strike squads." tabindex="0">Max Squad Size</div>
                    <div class="text-right">{{ $plan->max_squad_size }}</div>

                    <div class="font-medium text-base-content/70 tooltip tooltip-bottom cursor-help" data-tip="Tolerance (±) that keeps squads aligned on readiness." tabindex="0">Cohesion tolerance</div>
                    <div class="text-right">±{{ $plan->squad_cohesion_tolerance }}</div>

                    <div class="font-medium text-base-content/70 tooltip tooltip-bottom cursor-help" data-tip="Recent activity (hours) factored into Target Priority Score." tabindex="0">Activity window</div>
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

                        <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-name">
                            <span class="text-sm font-medium">Name</span>
                            <input id="war-plan-{{ $plan->id }}-settings-name" type="text" name="name" class="input w-full" value="{{ old('name', $plan->name) }}" required
                                   aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                                   @if($errors->has('name')) aria-describedby="war-plan-{{ $plan->id }}-settings-name-error" @endif>
                            @error('name')
                                <span id="war-plan-{{ $plan->id }}-settings-name-error" class="text-xs text-error">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-plan-type">
                                <span class="text-sm font-medium">Plan type</span>
                                <select id="war-plan-{{ $plan->id }}-settings-plan-type" name="plan_type" class="select w-full"
                                        aria-describedby="war-plan-{{ $plan->id }}-settings-plan-type-help{{ $errors->has('plan_type') ? ' war-plan-'.$plan->id.'-settings-plan-type-error' : '' }}"
                                        aria-invalid="{{ $errors->has('plan_type') ? 'true' : 'false' }}">
                                    @foreach ($warTypes as $key => $label)
                                        <option value="{{ $key }}" @selected(old('plan_type', $plan->plan_type) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span id="war-plan-{{ $plan->id }}-settings-plan-type-help" class="text-xs nexus-text-muted">Default declaration applied to new targets.</span>
                                @error('plan_type')
                                    <span id="war-plan-{{ $plan->id }}-settings-plan-type-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-preferred-targets">
                                <span class="text-sm font-medium">Preferred targets / nation</span>
                                <input id="war-plan-{{ $plan->id }}-settings-preferred-targets" type="number" name="preferred_targets_per_nation" class="input w-full" min="1" max="6" value="{{ old('preferred_targets_per_nation', $plan->preferred_targets_per_nation) }}"
                                       aria-invalid="{{ $errors->has('preferred_targets_per_nation') ? 'true' : 'false' }}"
                                       @if($errors->has('preferred_targets_per_nation')) aria-describedby="war-plan-{{ $plan->id }}-settings-preferred-targets-error" @endif>
                                @error('preferred_targets_per_nation')
                                    <span id="war-plan-{{ $plan->id }}-settings-preferred-targets-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-max-squad-size">
                                <span class="text-sm font-medium">Max squad size</span>
                                <input id="war-plan-{{ $plan->id }}-settings-max-squad-size" type="number" name="max_squad_size" class="input w-full" min="1" max="10" value="{{ old('max_squad_size', $plan->max_squad_size) }}"
                                       aria-invalid="{{ $errors->has('max_squad_size') ? 'true' : 'false' }}"
                                       @if($errors->has('max_squad_size')) aria-describedby="war-plan-{{ $plan->id }}-settings-max-squad-size-error" @endif>
                                @error('max_squad_size')
                                    <span id="war-plan-{{ $plan->id }}-settings-max-squad-size-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-cohesion-tolerance">
                                <span class="text-sm font-medium">Cohesion (±)</span>
                                <input id="war-plan-{{ $plan->id }}-settings-cohesion-tolerance" type="number" name="squad_cohesion_tolerance" class="input w-full" min="1" max="50" value="{{ old('squad_cohesion_tolerance', $plan->squad_cohesion_tolerance) }}"
                                       aria-invalid="{{ $errors->has('squad_cohesion_tolerance') ? 'true' : 'false' }}"
                                       @if($errors->has('squad_cohesion_tolerance')) aria-describedby="war-plan-{{ $plan->id }}-settings-cohesion-tolerance-error" @endif>
                                @error('squad_cohesion_tolerance')
                                    <span id="war-plan-{{ $plan->id }}-settings-cohesion-tolerance-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-activity-window">
                                <span class="text-sm font-medium">Activity window (h)</span>
                                <input id="war-plan-{{ $plan->id }}-settings-activity-window" type="number" name="activity_window_hours" class="input w-full" min="12" max="240" value="{{ old('activity_window_hours', $plan->activity_window_hours) }}"
                                       aria-invalid="{{ $errors->has('activity_window_hours') ? 'true' : 'false' }}"
                                       @if($errors->has('activity_window_hours')) aria-describedby="war-plan-{{ $plan->id }}-settings-activity-window-error" @endif>
                                @error('activity_window_hours')
                                    <span id="war-plan-{{ $plan->id }}-settings-activity-window-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-settings-discord-forum">
                                <span class="text-sm font-medium">Discord forum override</span>
                                <input id="war-plan-{{ $plan->id }}-settings-discord-forum" type="text" name="discord_forum_channel_id" class="input w-full" placeholder="Use default from War Room settings" value="{{ old('discord_forum_channel_id', $plan->discord_forum_channel_id) }}"
                                       aria-invalid="{{ $errors->has('discord_forum_channel_id') ? 'true' : 'false' }}"
                                       @if($errors->has('discord_forum_channel_id')) aria-describedby="war-plan-{{ $plan->id }}-settings-discord-forum-error" @endif>
                                @error('discord_forum_channel_id')
                                    <span id="war-plan-{{ $plan->id }}-settings-discord-forum-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                        </div>

                        <label class="label cursor-pointer justify-start gap-3" for="war-plan-{{ $plan->id }}-settings-suppress-counters">
                            <input id="war-plan-{{ $plan->id }}-settings-suppress-counters" class="toggle toggle-primary" type="checkbox" name="suppress_counters_when_active" value="1" {{ old('suppress_counters_when_active', $plan->suppress_counters_when_active) ? 'checked' : '' }}
                                   aria-invalid="{{ $errors->has('suppress_counters_when_active') ? 'true' : 'false' }}"
                                   @if($errors->has('suppress_counters_when_active')) aria-describedby="war-plan-{{ $plan->id }}-settings-suppress-counters-error" @endif>
                            <span class="">Suppress counters</span>
                        </label>
                        @error('suppress_counters_when_active')
                            <span id="war-plan-{{ $plan->id }}-settings-suppress-counters-error" class="text-xs text-error">{{ $message }}</span>
                        @enderror

                        <div>
                            <button id="war-plan-{{ $plan->id }}-settings-submit" type="submit" class="btn btn-primary btn-sm">Save changes</button>
                        </div>
                    </form>
                </div>
            </x-card>

            <x-card title="Alliances">
                <x-slot:menu>
                    <span class="tooltip tooltip-left cursor-help" role="img" aria-label="Alliance assignment guidance" data-tip="Friendly alliances supply our assignment pool. Enemy alliances seed targets." tabindex="0"><x-icon name="o-information-circle" class="size-4" aria-hidden="true" /></span>
                </x-slot:menu>

                <div class="space-y-6">
                    <div>
                        <h6 class="font-semibold">Friendly</h6>
                        <ul class="mt-2 space-y-2">
                            @forelse ($plan->friendlyAlliances as $alliance)
                                <li class="flex items-center justify-between rounded-box border border-base-300 px-3 py-2">
                                    <span>{{ $alliance->alliance->name ?? 'Alliance #'.$alliance->alliance_id }}</span>
                                    <form method="post" action="{{ route('admin.war-plans.alliances.destroy', [$plan, $alliance]) }}" class="tooltip tooltip-left" data-tip="Remove friendly alliance" data-confirm="Remove this friendly alliance from the plan's assignment pool?" data-confirm-title="Remove friendly alliance?" data-confirm-label="Remove alliance" data-confirm-tone="error">
                                        @csrf
                                        @method('DELETE')
                                        <button id="war-plan-{{ $plan->id }}-friendly-alliance-{{ $alliance->id }}-remove" class="btn btn-ghost btn-sm text-error" type="submit" aria-label="Remove friendly alliance {{ $alliance->alliance->name ?? '#'.$alliance->alliance_id }}">
                                            <x-icon name="o-x-circle" class="size-4" aria-hidden="true" />
                                        </button>
                                    </form>
                                </li>
                            @empty
                                <li class="nexus-text-muted">Using alliance membership defaults.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div>
                        <h6 class="font-semibold">Enemy</h6>
                        <ul class="mt-2 space-y-2">
                            @forelse ($plan->enemyAlliances as $alliance)
                                <li class="flex items-center justify-between rounded-box border border-base-300 px-3 py-2">
                                    <span>{{ $alliance->alliance->name ?? 'Alliance #'.$alliance->alliance_id }}</span>
                                    <form method="post" action="{{ route('admin.war-plans.alliances.destroy', [$plan, $alliance]) }}" class="tooltip tooltip-left" data-tip="Remove enemy alliance" data-confirm="Remove this enemy alliance from the plan's target pool?" data-confirm-title="Remove enemy alliance?" data-confirm-label="Remove alliance" data-confirm-tone="error">
                                        @csrf
                                        @method('DELETE')
                                        <button id="war-plan-{{ $plan->id }}-enemy-alliance-{{ $alliance->id }}-remove" class="btn btn-ghost btn-sm text-error" type="submit" aria-label="Remove enemy alliance {{ $alliance->alliance->name ?? '#'.$alliance->alliance_id }}">
                                            <x-icon name="o-x-circle" class="size-4" aria-hidden="true" />
                                        </button>
                                    </form>
                                </li>
                            @empty
                                <li class="nexus-text-muted">Add enemy alliances to drive target discovery.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <div class="mt-6 border-t border-base-300 pt-4">
                    <form method="post" action="{{ route('admin.war-plans.alliances.store', $plan) }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-alliance-add-id">
                                <span class="text-sm font-medium">Alliance ID</span>
                                <input id="war-plan-{{ $plan->id }}-alliance-add-id" type="number" min="1" class="input w-full" name="alliance_id" placeholder="1234" required
                                       aria-describedby="war-plan-{{ $plan->id }}-alliance-add-id-help{{ $errors->has('alliance_id') ? ' war-plan-'.$plan->id.'-alliance-add-id-error' : '' }}"
                                       aria-invalid="{{ $errors->has('alliance_id') ? 'true' : 'false' }}">
                                @error('alliance_id')
                                    <span id="war-plan-{{ $plan->id }}-alliance-add-id-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                            <label class="block space-y-2" for="war-plan-{{ $plan->id }}-alliance-add-role">
                                <span class="text-sm font-medium">Role</span>
                                <select id="war-plan-{{ $plan->id }}-alliance-add-role" name="role" class="select w-full"
                                        aria-invalid="{{ $errors->has('role') ? 'true' : 'false' }}"
                                        @if($errors->has('role')) aria-describedby="war-plan-{{ $plan->id }}-alliance-add-role-error" @endif>
                                    <option value="friendly">Friendly</option>
                                    <option value="enemy">Enemy</option>
                                </select>
                                @error('role')
                                    <span id="war-plan-{{ $plan->id }}-alliance-add-role-error" class="text-xs text-error">{{ $message }}</span>
                                @enderror
                            </label>
                        </div>
                        <button id="war-plan-{{ $plan->id }}-alliance-add-submit" type="submit" class="btn btn-outline btn-primary btn-sm w-full">Add alliance</button>
                        <p id="war-plan-{{ $plan->id }}-alliance-add-id-help" class="mb-0 text-sm nexus-text-muted">Alliance IDs appear in PW URLs (e.g. <code>.../alliance/id=1234</code>).</p>
                    </form>
                </div>
            </x-card>

            <x-card title="Automation & Notifications">
                <div class="space-y-2">
                    <form method="post" action="{{ route('admin.war-plans.recompute', $plan) }}" class="tooltip tooltip-left w-full" data-tip="Refresh Target Priority Scores using the latest intelligence.">
                        @csrf
                        <button id="war-plan-{{ $plan->id }}-recompute-priorities" class="btn btn-outline btn-sm w-full" type="submit">Recompute priorities</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-plans.auto-assign', $plan) }}" class="tooltip tooltip-left w-full" data-tip="Regenerate assignments for unlocked slots.">
                        @csrf
                        <button id="war-plan-{{ $plan->id }}-auto-generate-assignments" class="btn btn-outline btn-sm w-full" type="submit">Auto-generate assignments</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-plans.activate', $plan) }}" data-confirm="Activate this war plan and make it the current operational plan?" data-confirm-title="Activate war plan?" data-confirm-label="Activate plan">
                        @csrf
                        <button id="war-plan-{{ $plan->id }}-activate" class="btn btn-outline btn-success btn-sm w-full" type="submit">Activate plan</button>
                    </form>
                    <form method="post" action="{{ route('admin.war-plans.archive', $plan) }}" data-confirm="Archive this war plan? It will leave the active planning workspace." data-confirm-title="Archive war plan?" data-confirm-label="Archive plan" data-confirm-tone="error">
                        @csrf
                        <button id="war-plan-{{ $plan->id }}-archive" class="btn btn-outline btn-error btn-sm w-full" type="submit">Archive plan</button>
                    </form>
                </div>

                <div class="mt-6 border-t border-base-300 pt-4">
                    <form method="post" action="{{ route('admin.war-plans.publish', $plan) }}" class="space-y-3" data-confirm="Publish these assignments with the selected notification options? This can send in-game mail or create Discord rooms." data-confirm-title="Publish assignments?" data-confirm-label="Publish assignments">
                        @csrf
                        <fieldset class="space-y-1">
                            <legend class="sr-only">Notification options</legend>
                            <label class="label cursor-pointer justify-start gap-3" for="war-plan-{{ $plan->id }}-publish-notify-in-game">
                                <input id="war-plan-{{ $plan->id }}-publish-notify-in-game" class="checkbox checkbox-sm" type="checkbox" name="notify_in_game" value="1"
                                       aria-invalid="{{ $errors->has('notify_in_game') ? 'true' : 'false' }}"
                                       @if($errors->has('notify_in_game')) aria-describedby="war-plan-{{ $plan->id }}-publish-notify-in-game-error" @endif>
                                <span class="">Send in-game mail</span>
                            </label>
                            @error('notify_in_game')
                                <span id="war-plan-{{ $plan->id }}-publish-notify-in-game-error" class="text-xs text-error">{{ $message }}</span>
                            @enderror
                            <label class="label cursor-pointer justify-start gap-3" for="war-plan-{{ $plan->id }}-publish-notify-discord-room">
                                <input id="war-plan-{{ $plan->id }}-publish-notify-discord-room" class="checkbox checkbox-sm" type="checkbox" name="notify_discord_room" value="1"
                                       aria-invalid="{{ $errors->has('notify_discord_room') ? 'true' : 'false' }}"
                                       @if($errors->has('notify_discord_room')) aria-describedby="war-plan-{{ $plan->id }}-publish-notify-discord-room-error" @endif>
                                <span class="">Create Discord War Room</span>
                            </label>
                            @error('notify_discord_room')
                                <span id="war-plan-{{ $plan->id }}-publish-notify-discord-room-error" class="text-xs text-error">{{ $message }}</span>
                            @enderror
                        </fieldset>
                        <button id="war-plan-{{ $plan->id }}-publish-submit" class="btn btn-primary btn-sm w-full" type="submit">Publish assignments</button>
                        <div class="flex gap-2 pt-1">
                            <a href="{{ route('admin.war-plans.export', $plan) }}" class="btn btn-outline btn-primary flex-1">Export</a>
                            <button id="war-plan-{{ $plan->id }}-import-open" class="btn btn-outline flex-1" type="button" onclick="document.getElementById('importPlanModal').showModal()">Import</button>
                        </div>
                    </form>
                </div>
            </x-card>
        </div>

    <dialog class="modal" id="quickAssignModal" aria-label="Quick assignment">
        <div class="modal-box max-w-2xl" x-data>
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold">Quick assign</h3>
                    <p class="text-sm nexus-text-muted">Assign an unassigned friendly directly to an available target.</p>
                </div>
                <form method="dialog">
                    <button id="war-plan-{{ $plan->id }}-quick-assign-close" class="btn btn-ghost btn-sm btn-circle" aria-label="Close quick assignment dialog">✕</button>
                </form>
            </div>

            <form method="post" :action="$store.warPlan.routes.manualAssignment" class="space-y-4">
                @csrf

                <label class="block space-y-2" for="war-plan-{{ $plan->id }}-quick-assign-friendly">
                    <span class="text-sm font-medium">Friendly nation</span>
                    <input id="war-plan-{{ $plan->id }}-quick-assign-friendly" type="text" class="input w-full" name="friendly_nation_id" :value="$store.warPlan.quickAssign?.id || ''" readonly
                           aria-describedby="war-plan-{{ $plan->id }}-quick-assign-friendly-name{{ $errors->has('friendly_nation_id') ? ' war-plan-'.$plan->id.'-quick-assign-friendly-error' : '' }}"
                           aria-invalid="{{ $errors->has('friendly_nation_id') ? 'true' : 'false' }}">
                    <span class="text-sm nexus-text-muted" id="war-plan-{{ $plan->id }}-quick-assign-friendly-name" x-text="$store.warPlan.quickAssign ? `${$store.warPlan.quickAssign.leader_name} (${$store.warPlan.quickAssign.nation_name})` : 'Select a friendly to assign'"></span>
                    @error('friendly_nation_id')
                        <span id="war-plan-{{ $plan->id }}-quick-assign-friendly-error" class="text-xs text-error">{{ $message }}</span>
                    @enderror
                    <a x-show="$store.warPlan.quickAssign?.id" :href="`https://politicsandwar.com/nation/id=${$store.warPlan.quickAssign?.id}`" target="_blank" rel="noopener noreferrer" class="link link-hover text-sm">
                        Open nation in-game
                    </a>
                </label>

                <label class="block space-y-2" for="war-plan-{{ $plan->id }}-quick-assign-target">
                    <span class="text-sm font-medium">Target</span>
                    <select id="war-plan-{{ $plan->id }}-quick-assign-target" name="war_plan_target_id" class="select w-full" required
                            aria-invalid="{{ $errors->has('war_plan_target_id') ? 'true' : 'false' }}"
                            @if($errors->has('war_plan_target_id')) aria-describedby="war-plan-{{ $plan->id }}-quick-assign-target-error" @endif>
                        <template x-for="target in $store.warPlan.targets" :key="target.id">
                            <template x-if="$store.warPlan.targetAvailable(target)">
                                <option :value="target.id" x-text="`${target.nation?.leader_name ?? 'Unknown'} (TPS ${formatNumber(target.target_priority_score, 1)}) • Slots ${(target.assignments_count ?? 0)} / ${$store.warPlan.preferredAssignmentsPerTarget || '-'}`"></option>
                            </template>
                        </template>
                    </select>
                    @error('war_plan_target_id')
                        <span id="war-plan-{{ $plan->id }}-quick-assign-target-error" class="text-xs text-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block space-y-2" for="war-plan-{{ $plan->id }}-quick-assign-match-score">
                    <span class="text-sm font-medium">Match score (optional)</span>
                    <input id="war-plan-{{ $plan->id }}-quick-assign-match-score" type="number" name="match_score" class="input w-full" min="0" max="100" placeholder="50"
                           aria-invalid="{{ $errors->has('match_score') ? 'true' : 'false' }}"
                           @if($errors->has('match_score')) aria-describedby="war-plan-{{ $plan->id }}-quick-assign-match-score-error" @endif>
                    @error('match_score')
                        <span id="war-plan-{{ $plan->id }}-quick-assign-match-score-error" class="text-xs text-error">{{ $message }}</span>
                    @enderror
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button id="war-plan-{{ $plan->id }}-quick-assign-cancel" type="button" class="btn btn-ghost" onclick="document.getElementById('quickAssignModal').close()">Cancel</button>
                    <button id="war-plan-{{ $plan->id }}-quick-assign-submit" type="submit" class="btn btn-primary">Assign</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button id="war-plan-{{ $plan->id }}-quick-assign-backdrop-close" aria-label="Close quick assignment dialog">close</button>
        </form>
    </dialog>

    <div class="mt-1 grid gap-4">
        <div>
            <div class="nexus-panel overflow-hidden" x-data="targetsTable()">
                <div class="nexus-panel__header flex-col lg:flex-row lg:items-center">
                    <div>
                        <h5 class="nexus-section-title">Targets</h5>
                        <small class="nexus-text-muted">TPS = Target Priority Score. Hover or focus the badge to inspect factor breakdowns.</small>
                    </div>
                    <form class="ml-auto flex flex-wrap gap-2" method="post" action="{{ route('admin.war-plans.targets.store', $plan) }}">
                        @csrf
                        <div class="flex items-center gap-2">
                            <label for="war-plan-{{ $plan->id }}-target-add-nation" class="text-sm font-medium text-base-content/70 tooltip tooltip-bottom cursor-help" data-tip="Nation ID pulled from the Politics & War profile" tabindex="0">Nation ID</label>
                            <input id="war-plan-{{ $plan->id }}-target-add-nation" type="number" class="input w-full" name="nation_id" min="1" placeholder="e.g. 123456" required
                                   aria-invalid="{{ $errors->has('nation_id') ? 'true' : 'false' }}"
                                   @if($errors->has('nation_id')) aria-describedby="war-plan-{{ $plan->id }}-target-add-nation-error" @endif>
                        </div>
                        <label class="sr-only" for="war-plan-{{ $plan->id }}-target-add-war-type">Preferred war type</label>
                        <select id="war-plan-{{ $plan->id }}-target-add-war-type" name="preferred_war_type" class="select select-sm"
                                aria-invalid="{{ $errors->has('preferred_war_type') ? 'true' : 'false' }}"
                                @if($errors->has('preferred_war_type')) aria-describedby="war-plan-{{ $plan->id }}-target-add-war-type-error" @endif>
                            <option value="">War type (default {{ strtoupper($plan->plan_type) }})</option>
                            @foreach ($warTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button id="war-plan-{{ $plan->id }}-target-add-submit" class="btn btn-outline btn-primary btn-sm" type="submit">Add target</button>
                        @error('nation_id')
                            <span id="war-plan-{{ $plan->id }}-target-add-nation-error" class="w-full text-xs text-error">{{ $message }}</span>
                        @enderror
                        @error('preferred_war_type')
                            <span id="war-plan-{{ $plan->id }}-target-add-war-type-error" class="w-full text-xs text-error">{{ $message }}</span>
                        @enderror
                    </form>
                </div>
                <div class="p-0">
                    <div class="p-3 flex flex-wrap gap-2 items-center">
                        <div class="flex w-full max-w-sm items-center gap-2">
                            <label for="war-plan-{{ $plan->id }}-targets-search" class="text-sm font-medium text-base-content/70">Search</label>
                            <input id="war-plan-{{ $plan->id }}-targets-search" type="search" class="input w-full" placeholder="Enemy, alliance, TPS, status" x-model.debounce.300ms="search">
                        </div>
                        <div class="ml-auto flex items-center gap-2">
                            <a href="{{ route('admin.war-plans.targets.export-csv', $plan) }}" class="btn btn-outline btn-success btn-sm">
                                <x-icon name="o-arrow-down-tray" class="size-4" aria-hidden="true" /> Export CSV
                            </a>
                            <button id="war-plan-{{ $plan->id }}-targets-refresh" type="button" class="btn btn-outline btn-sm" @click="fetchTargets">
                                <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" /> Refresh
                            </button>
                            <div class="items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                                <div class="loading loading-spinner loading-sm text-primary" role="status">
                                    
                                </div>
                                <span class="text-sm nexus-text-muted">Loading targets…</span>
                            </div>
                            <span class="text-sm text-error" x-show="error" x-text="error"></span>
                            <button id="war-plan-{{ $plan->id }}-targets-retry" class="btn btn-outline btn-sm" type="button" x-show="error" @click="fetchTargets">Retry</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-box border border-base-300" id="targets-table" x-show="!loading" x-cloak style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-zebra align-middle [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-base-200/60" data-sortable="false">
                            <thead class="bg-base-200 sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Enemy</th>
                                <th>Alliance</th>
                                <th>TPS</th>
                                <th>Slots</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>War type</th>
                                <th class="text-right" data-sortable="false">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredTargets.length && !error">
                                <tr>
                                    <td colspan="8" class="text-center py-4 nexus-text-muted">No targets yet. Add alliances or seed specific nation IDs above.</td>
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
                                                <div class="text-sm nexus-text-muted" x-text="target.nation.nation_name"></div>
                                                <div class="text-sm">
                                                    Score <span x-text="formatNumber(target.nation.score, 2)"></span>
                                                    • Cities <span x-text="target.nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="text-sm nexus-text-muted">
                                                    Soldiers <span x-text="formatNumber(target.nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(target.nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="text-sm nexus-text-muted">
                                                    Aircraft <span x-text="formatNumber(target.nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(target.nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation">
                                            <span class="font-semibold nexus-text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="target.nation?.alliance">
                                            <div>
                                                <a :href="`https://politicsandwar.com/alliance/id=${target.nation.alliance.id}`" target="_blank" x-text="target.nation.alliance.name"></a>
                                                <div class="text-sm nexus-text-muted" x-text="target.nation.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation?.alliance">
                                            <span class="nexus-text-muted">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge badge-error tooltip tooltip-left cursor-help" data-tip="Stored Target Priority Score metadata" tabindex="0" x-text="formatNumber(target.target_priority_score, 1)"></span>
                                    </td>
                                    <td>
                                        <span class="badge tooltip tooltip-left cursor-help"
                                              :class="badgeForSlots(target)"
                                              data-tip="Assigned friendlies / preferred slots"
                                              tabindex="0"
                                              x-text="`${target.assignments_count ?? 0} / ${slotsFor(target)}`">
                                        </span>
                                    </td>
                                    <td>
                                        <template x-if="target.nation">
                                            <div class="flex flex-wrap gap-1">
                                                <span class="badge badge-warning tooltip tooltip-left cursor-help" data-tip="In vacation mode" tabindex="0" x-show="(target.nation.vacation_mode_turns ?? 0) > 0">VM</span>
                                                <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="On beige" tabindex="0" x-show="(target.nation.beige_turns ?? 0) > 0" x-text="`${target.nation.beige_turns} beige`"></span>
                                                <span class="badge badge-info tooltip tooltip-left cursor-help" data-tip="Offensive / defensive active wars" tabindex="0"
                                                      x-show="(target.nation.offensive_wars_count ?? 0) + (target.nation.defensive_wars_count ?? 0) > 0"
                                                      x-text="`Wars ${target.nation.offensive_wars_count ?? 0} / ${target.nation.defensive_wars_count ?? 0}`"></span>
                                                <span class="text-sm nexus-text-muted" x-show="(target.nation.offensive_wars_count ?? 0) + (target.nation.defensive_wars_count ?? 0) === 0 && (target.nation.beige_turns ?? 0) === 0 && (target.nation.vacation_mode_turns ?? 0) === 0">No flags</span>
                                            </div>
                                        </template>
                                        <template x-if="!target.nation">
                                            <span class="nexus-text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td x-text="lastActive(target)"></td>
                                    <td style="width: 180px;">
                                        <form method="post" :action="routes.updateTargetWarType(target.id)" class="tooltip tooltip-left" data-tip="Preferred declaration for this enemy">
                                            @csrf
                                            <label class="sr-only" :for="`war-plan-{{ $plan->id }}-target-${target.id}-war-type`">Preferred declaration for this enemy</label>
                                            <select class="select select-sm"
                                                    :id="`war-plan-{{ $plan->id }}-target-${target.id}-war-type`"
                                                    name="preferred_war_type"
                                                    aria-label="Preferred declaration for this enemy"
                                                    @change="$event.target.form.submit()">
                                                <template x-for="(label, value) in warTypes" :key="value">
                                                    <option :value="value" :selected="target.preferred_war_type === value" x-text="label"></option>
                                                </template>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button :id="`war-plan-{{ $plan->id }}-target-${target.id}-meta-toggle`" class="btn btn-outline btn-sm" type="button" @click="toggleTargetMeta(target.id)">
                                                Meta
                                            </button>
                                            <button :id="`war-plan-{{ $plan->id }}-target-${target.id}-assign-open`" class="btn btn-outline btn-primary btn-sm" type="button" @click="setActiveTarget(target); $nextTick(() => document.getElementById('assignTargetModal').showModal())">
                                                Assign
                                            </button>
                                            <form method="post"
                                                  :action="routes.deleteTarget(target.id)"
                                                  data-confirm="Remove this target from the plan? Its assignments will no longer be part of this plan."
                                                  data-confirm-title="Remove target?"
                                                  data-confirm-label="Remove target"
                                                  data-confirm-tone="error">
                                                @csrf
                                                @method('DELETE')
                                                <button :id="`war-plan-{{ $plan->id }}-target-${target.id}-remove`" class="btn btn-outline btn-error btn-sm" type="submit" :aria-label="`Remove target ${target.nation?.leader_name ?? target.nation_id}`"><x-icon name="o-trash" class="size-4" aria-hidden="true" /></button>
                                            </form>
                                        </div>
                                        <div class="mt-2 text-left" x-show="isTargetMetaOpen(target.id)" x-transition>
                                            <pre class="mb-0 text-sm nexus-text-muted" x-text="prettyMeta(target.meta)"></pre>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <dialog class="modal" id="assignTargetModal" aria-label="Manual target assignments">
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
                                <p class="text-sm nexus-text-muted">Assign in-range friendlies or add one manually by nation ID.</p>
                            </div>
                            <form method="dialog">
                                <button id="war-plan-{{ $plan->id }}-assign-target-close" class="btn btn-ghost btn-sm btn-circle" aria-label="Close manual target assignments dialog">✕</button>
                            </form>
                        </div>

                        <div class="space-y-4">
                                <h6 class="font-semibold">In-range friendlies</h6>
                                <p class="text-sm nexus-text-muted">All nations in war range, sorted with recommended options first.</p>
                                <div class="overflow-x-auto rounded-box border border-base-300">
                                    <table class="table table-zebra table-sm" data-sortable="false">
                                        <thead>
                                        <tr>
                                            <th>Friendly</th>
                                            <th>Alliance</th>
                                            <th>Strength</th>
                                            <th>Wars</th>
                                            <th>Match score</th>
                                            <th>Assignments</th>
                                            <th>Open slots</th>
                                            <th class="text-right" data-sortable="false">Action</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <template x-if="candidatesLoading">
                                            <tr>
                                                <td colspan="8" class="text-center nexus-text-muted py-3">Loading candidates…</td>
                                            </tr>
                                        </template>
                                        <template x-if="!candidatesLoading && candidatesError">
                                            <tr>
                                                <td colspan="8" class="py-3 text-center text-error" x-text="candidatesError"></td>
                                            </tr>
                                        </template>
                                        <template x-if="!candidatesLoading && !candidatesError && !candidatesForActiveTarget().length">
                                            <tr>
                                                <td colspan="8" class="text-center nexus-text-muted py-3">No friendlies are in war range right now.</td>
                                            </tr>
                                        </template>
                                        <template x-for="candidate in (candidatesLoading ? [] : candidatesForActiveTarget())" :key="candidate.friendly.id">
                                            <tr>
                                                <td>
                                                    <a :href="`https://politicsandwar.com/nation/id=${candidate.friendly.id}`" target="_blank" rel="noopener noreferrer" class="font-semibold" x-text="candidate.friendly.leader_name"></a>
                                                    <div class="text-sm nexus-text-muted" x-text="candidate.friendly.nation_name"></div>
                                                </td>
                                                <td>
                                                    <template x-if="candidate.friendly.alliance">
                                                        <div>
                                                            <a :href="`https://politicsandwar.com/alliance/id=${candidate.friendly.alliance.id}`" target="_blank" x-text="candidate.friendly.alliance.name"></a>
                                                            <div class="text-sm nexus-text-muted" x-text="candidate.friendly.alliance.acronym"></div>
                                                        </div>
                                                    </template>
                                                    <template x-if="!candidate.friendly.alliance">
                                                        <span class="nexus-text-muted">No alliance</span>
                                                    </template>
                                                </td>
                                                <td>
                                                    <div class="text-sm">
                                                        Score <span x-text="formatNumber(candidate.friendly.score, 2)"></span>
                                                        • Cities <span x-text="candidate.friendly.num_cities ?? 0"></span>
                                                    </div>
                                                    <div class="text-sm nexus-text-muted">
                                                        Soldiers <span x-text="formatNumber(candidate.friendly.military?.soldiers || 0)"></span>
                                                        • Tanks <span x-text="formatNumber(candidate.friendly.military?.tanks || 0)"></span>
                                                    </div>
                                                    <div class="text-sm nexus-text-muted">
                                                        Aircraft <span x-text="formatNumber(candidate.friendly.military?.aircraft || 0)"></span>
                                                        • Ships <span x-text="formatNumber(candidate.friendly.military?.ships || 0)"></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="Offensive / defensive wars" tabindex="0">
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
                                                        <button :id="`war-plan-{{ $plan->id }}-assign-target-${activeTarget?.id ?? 'none'}-candidate-${candidate.friendly.id}-submit`" type="submit" class="btn btn-outline btn-primary btn-sm">Assign</button>
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
                                    <label class="block space-y-2" for="war-plan-{{ $plan->id }}-assign-target-friendly">
                                        <span class="text-sm font-medium">Friendly nation ID</span>
                                        <input id="war-plan-{{ $plan->id }}-assign-target-friendly" type="number" name="friendly_nation_id" class="input w-full"
                                               list="friendly-options-{{ $plan->id }}" placeholder="Type ID or select" required
                                               aria-describedby="war-plan-{{ $plan->id }}-assign-target-friendly-help{{ $errors->has('friendly_nation_id') ? ' war-plan-'.$plan->id.'-assign-target-friendly-error' : '' }}"
                                               aria-invalid="{{ $errors->has('friendly_nation_id') ? 'true' : 'false' }}">
                                        @error('friendly_nation_id')
                                            <span id="war-plan-{{ $plan->id }}-assign-target-friendly-error" class="text-xs text-error">{{ $message }}</span>
                                        @enderror
                                    </label>
                                    <label class="block space-y-2" for="war-plan-{{ $plan->id }}-assign-target-match-score">
                                        <span class="text-sm font-medium">Match score</span>
                                        <input id="war-plan-{{ $plan->id }}-assign-target-match-score" type="number" name="match_score" class="input w-full" min="0" max="100" placeholder="50"
                                               aria-invalid="{{ $errors->has('match_score') ? 'true' : 'false' }}"
                                               @if($errors->has('match_score')) aria-describedby="war-plan-{{ $plan->id }}-assign-target-match-score-error" @endif>
                                        @error('match_score')
                                            <span id="war-plan-{{ $plan->id }}-assign-target-match-score-error" class="text-xs text-error">{{ $message }}</span>
                                        @enderror
                                    </label>
                                    <div class="flex items-end">
                                        <button id="war-plan-{{ $plan->id }}-assign-target-manual-submit" type="submit" class="btn btn-primary w-full">Assign</button>
                                    </div>
                                </form>
                                <p id="war-plan-{{ $plan->id }}-assign-target-friendly-help" class="mb-0 text-sm nexus-text-muted">Use the datalist to search by leader name if you already know the responder.</p>
                        </div>
                    </div>
                    <form method="dialog" class="modal-backdrop">
                        <button id="war-plan-{{ $plan->id }}-assign-target-backdrop-close" aria-label="Close manual target assignments dialog">close</button>
                    </form>
                </dialog>
            </div>
        </div>

        <div>
            <div class="nexus-panel overflow-hidden" x-data="assignmentsTable()">
                <div class="border-b border-base-300 px-6 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h5 class="nexus-section-title">Assignments &amp; squads</h5>
                            <small class="nexus-text-muted">Full overview of friendlies per target. Max six offensive slots, three defensive.</small>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.war-plans.assignments.export-csv', $plan) }}" class="btn btn-outline btn-success btn-sm">
                                <x-icon name="o-arrow-down-tray" class="size-4" aria-hidden="true" /> Export CSV
                            </a>
                            <button id="war-plan-{{ $plan->id }}-assignments-refresh" type="button" class="btn btn-outline btn-sm" @click="fetchAssignments">
                                <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" /> Refresh
                            </button>
                            <div class="items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                                <div class="loading loading-spinner loading-sm text-primary" role="status">
                                    
                                </div>
                                <span class="text-sm nexus-text-muted">Loading assignments…</span>
                            </div>
                            <span class="text-sm text-error" x-show="error" x-text="error"></span>
                            <button id="war-plan-{{ $plan->id }}-assignments-retry" class="btn btn-outline btn-sm" type="button" x-show="error" @click="fetchAssignments">Retry</button>
                        </div>
                    </div>
                </div>
                <div class="p-0">
                    <div class="p-3 flex flex-wrap gap-2 items-center">
                        <div class="flex w-full max-w-sm items-center gap-2">
                            <label for="war-plan-{{ $plan->id }}-assignments-search" class="text-sm font-medium text-base-content/70">Search</label>
                            <input id="war-plan-{{ $plan->id }}-assignments-search" type="search" class="input w-full" placeholder="Friendly, target, status" x-model.debounce.300ms="search">
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-box border border-base-300" x-show="!loading" x-cloak style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-zebra align-middle [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-base-200/60" data-sortable="false">
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
                                <th class="text-right" data-sortable="false">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredAssignments.length && !error">
                                <tr>
                                    <td colspan="9" class="text-center py-4 nexus-text-muted">No assignments yet. Auto-generate or use the Assign buttons to attach friendlies.</td>
                                </tr>
                            </template>
                            <template x-for="assignment in filteredAssignments" :key="assignment.id">
                                <tr>
                                    <td>
                                        <template x-if="assignment.target?.nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${assignment.target.nation.id}`" target="_blank" class="font-semibold" x-text="assignment.target.nation.leader_name"></a>
                                                <div class="text-sm nexus-text-muted" x-text="assignment.target.nation.nation_name"></div>
                                                <div class="text-sm">
                                                    Score <span x-text="formatNumber(assignment.target.nation.score, 2)"></span>
                                                    • Cities <span x-text="assignment.target.nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="text-sm nexus-text-muted">
                                                    Soldiers <span x-text="formatNumber(assignment.target.nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(assignment.target.nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="text-sm nexus-text-muted">
                                                    Aircraft <span x-text="formatNumber(assignment.target.nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(assignment.target.nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.target?.nation">
                                            <span class="nexus-text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td x-text="assignment.squad?.label ?? 'Unassigned'"></td>
                                    <td>
                                        <template x-if="assignment.friendly_nation">
                                            <div>
                                                <a :href="`https://politicsandwar.com/nation/id=${assignment.friendly_nation.id}`" target="_blank" class="font-semibold" x-text="assignment.friendly_nation.leader_name"></a>
                                                <div class="text-sm nexus-text-muted" x-text="assignment.friendly_nation.nation_name"></div>
                                                <div class="text-sm">
                                                    Score <span x-text="formatNumber(assignment.friendly_nation.score, 2)"></span>
                                                    • Cities <span x-text="assignment.friendly_nation.num_cities ?? 0"></span>
                                                </div>
                                                <div class="text-sm nexus-text-muted">
                                                    Soldiers <span x-text="formatNumber(assignment.friendly_nation.military?.soldiers || 0)"></span>
                                                    • Tanks <span x-text="formatNumber(assignment.friendly_nation.military?.tanks || 0)"></span>
                                                </div>
                                                <div class="text-sm nexus-text-muted">
                                                    Aircraft <span x-text="formatNumber(assignment.friendly_nation.military?.aircraft || 0)"></span>
                                                    • Ships <span x-text="formatNumber(assignment.friendly_nation.military?.ships || 0)"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.friendly_nation">
                                            <span class="nexus-text-muted">Unknown</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="assignment.friendly_nation?.alliance">
                                            <div>
                                                <a :href="`https://politicsandwar.com/alliance/id=${assignment.friendly_nation.alliance.id}`" target="_blank" x-text="assignment.friendly_nation.alliance.name"></a>
                                                <div class="text-sm nexus-text-muted" x-text="assignment.friendly_nation.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!assignment.friendly_nation?.alliance">
                                            <span class="nexus-text-muted">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="Offensive / defensive wars currently active" tabindex="0">
                                            <span x-text="assignment.friendly_nation?.offensive_wars_count ?? 0"></span>
                                            /
                                            <span x-text="assignment.friendly_nation?.defensive_wars_count ?? 0"></span>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="Assigned targets vs capacity" tabindex="0"
                                              x-text="assignmentCapacity(assignment)">
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2 mb-1">
                                            <div class="h-2 grow overflow-hidden rounded-full bg-base-200" aria-label="Match strength">
                                                <div class="h-full bg-info transition-[width]" role="progressbar" :style="`width: ${Math.min(100, assignment.match_score ?? 0)}%`"
                                                     :aria-valuenow="assignment.match_score ?? 0" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <span class="badge badge-info" x-text="formatNumber(assignment.match_score, 1)"></span>
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <button :id="`war-plan-{{ $plan->id }}-assignment-${assignment.id}-details-toggle`" class="btn btn-outline btn-sm" type="button" @click="toggleAssignmentMeta(assignment.id)">
                                                Details
                                            </button>
                                            <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="Manual override" tabindex="0" x-show="assignment.is_overridden">Manual</span>
                                            <span class="badge badge-success tooltip tooltip-left cursor-help" data-tip="Locked assignment" tabindex="0" x-show="assignment.is_locked">Locked</span>
                                        </div>
                                        <div class="mt-2" x-show="isAssignmentMetaOpen(assignment.id)" x-transition>
                                            <pre class="mb-0 text-sm nexus-text-muted" x-text="prettyMeta(assignment.meta)"></pre>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <span class="badge badge-ghost uppercase" x-text="assignment.status"></span>
                                            <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="Friendly nation is on beige" tabindex="0" x-show="(assignment.friendly_nation?.beige_turns ?? 0) > 0">Beige</span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <form method="post" :action="routes.deleteAssignment(assignment.id)"
                                              data-confirm="Remove this assignment from the plan?"
                                              data-confirm-title="Remove assignment?"
                                              data-confirm-label="Remove assignment"
                                              data-confirm-tone="error">
                                            @csrf
                                            @method('DELETE')
                                            <button :id="`war-plan-{{ $plan->id }}-assignment-${assignment.id}-remove`" class="btn btn-outline btn-error btn-sm" type="submit" :aria-label="`Remove assignment for ${assignment.friendly_nation?.leader_name ?? assignment.friendly_nation_id}`"><x-icon name="o-trash" class="size-4" aria-hidden="true" /></button>
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

        <div>
            <div class="nexus-panel overflow-hidden">
                <div class="nexus-panel__header items-center justify-between">
                    <h5 class="nexus-section-title">Live attacks</h5>
                    <small class="nexus-text-muted">Filter window, scope, or attack type to focus the feed.</small>
                </div>
                <div class="p-6">
                    <form class="mb-3 grid gap-3 md:grid-cols-12" method="get">
                        <div class="md:col-span-3">
                            <label for="war-plan-{{ $plan->id }}-live-feed-minutes" class="mb-1 block text-sm font-medium">Minutes</label>
                            <input id="war-plan-{{ $plan->id }}-live-feed-minutes" type="number" class="input w-full" name="minutes" value="{{ request('minutes') }}"
                                   placeholder="{{ config('war.live_feed.default_window_minutes') }}">
                        </div>
                        <div class="md:col-span-3">
                            <label for="war-plan-{{ $plan->id }}-live-feed-scope" class="mb-1 block text-sm font-medium">Scope</label>
                            <select id="war-plan-{{ $plan->id }}-live-feed-scope" name="scope" class="select w-full">
                                <option value="both" @selected(request('scope') === 'both')>All</option>
                                <option value="ours" @selected(request('scope') === 'ours')>Friendlies</option>
                                <option value="theirs" @selected(request('scope') === 'theirs')>Enemies</option>
                            </select>
                        </div>
                        <div class="md:col-span-4">
                            <label for="war-plan-{{ $plan->id }}-live-feed-attack-types" class="mb-1 block text-sm font-medium">Attack types (CSV)</label>
                            <input id="war-plan-{{ $plan->id }}-live-feed-attack-types" type="text" class="input w-full" name="attack_types"
                                   value="{{ is_array(request('attack_types')) ? implode(',', request('attack_types')) : request('attack_types') }}"
                                   placeholder="ground,air">
                        </div>
                        <div class="flex items-end md:col-span-2">
                            <button id="war-plan-{{ $plan->id }}-live-feed-apply" class="btn btn-outline btn-primary w-full" type="submit">Apply</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto rounded-box border border-base-300" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-zebra align-middle" data-sortable="false">
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
                                    <td colspan="5" class="text-center py-4 nexus-text-muted">No attacks within the selected window.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-1 grid gap-4">
        <div>
            <div class="nexus-panel overflow-hidden">
                <div class="nexus-panel__header flex-col lg:flex-row lg:items-center">
                    <div>
                        <h5 class="nexus-section-title">Comparative Stats</h5>
                        <small class="nexus-text-muted">Quick glance at friendly vs enemy scale and militarization.</small>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <h6 class="flex justify-between font-semibold">
                                <span>Cities</span>
                                <span class="text-sm nexus-text-muted">Friendly {{ number_format($friendlyCityTotal) }} / Enemy {{ number_format($enemyCityTotal) }}</span>
                            </h6>
                            <div
                                class="tooltip tooltip-bottom cursor-help w-full"
                                data-tip="Friendly avg {{ number_format($friendlyCityAvg ?? 0, 1) }} | Enemy avg {{ number_format($enemyCityAvg ?? 0, 1) }}"
                                tabindex="0"
                                aria-label="Friendly average {{ number_format($friendlyCityAvg ?? 0, 1) }} cities; enemy average {{ number_format($enemyCityAvg ?? 0, 1) }} cities"
                            >
                                @php
                                    $cityTotal = max(1, $friendlyCityTotal + $enemyCityTotal);
                                    $friendlyCityPct = round(($friendlyCityTotal / $cityTotal) * 100, 1);
                                @endphp
                                <div class="h-2.5 overflow-hidden rounded-full bg-base-200" aria-hidden="true">
                                    <div class="h-full bg-primary" style="width: {{ $friendlyCityPct }}%"></div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <h6 class="flex justify-between font-semibold">
                                <span>Force Readiness</span>
                                <span class="text-sm nexus-text-muted">By unit type</span>
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
                                    <div class="flex justify-between text-sm">
                                        <span>{{ $label }}</span>
                                        <span class="nexus-text-muted">Friendly {{ number_format($friendlyVal) }} • Enemy {{ number_format($enemyVal) }}</span>
                                    </div>
                                    <div
                                        class="tooltip tooltip-bottom cursor-help w-full"
                                        data-tip="Friendly {{ number_format($friendlyVal) }} ({{ $friendlyPct }}%) | Enemy {{ number_format($enemyVal) }} ({{ $enemyPct }}%)"
                                        tabindex="0"
                                        aria-label="{{ $label }}: friendly {{ number_format($friendlyVal) }} units, {{ $friendlyPct }} percent; enemy {{ number_format($enemyVal) }} units, {{ $enemyPct }} percent"
                                    >
                                        <div class="flex h-2 overflow-hidden rounded-full bg-base-200" aria-hidden="true">
                                            <div class="h-full bg-primary" style="width: {{ $friendlyPct }}%"></div>
                                            <div class="h-full bg-error" style="width: {{ $enemyPct }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div>
                            <h6 class="font-semibold">Average Cities</h6>
                            <p class="mb-1"><span class="badge badge-primary">Friendly {{ number_format($friendlyCityAvg ?? 0, 1) }}</span></p>
                            <p class="mb-1"><span class="badge badge-error">Enemy {{ number_format($enemyCityAvg ?? 0, 1) }}</span></p>
                        </div>
                        <div>
                            <h6 class="font-semibold">Assignments Coverage</h6>
                            <div
                                class="tooltip tooltip-bottom cursor-help w-full"
                                data-tip="Assignments {{ $assignmentCount }} / Desired {{ $preferredSlotsTotal ?? 0 }}"
                                tabindex="0"
                                aria-label="{{ $assignmentCount }} assignments out of {{ $preferredSlotsTotal ?? 0 }} desired"
                            >
                                <div class="h-2.5 overflow-hidden rounded-full bg-base-200" aria-hidden="true">
                                    <div class="h-full bg-success" style="width: {{ $coverage ?? 0 }}%"></div>
                                </div>
                            </div>
                            <small class="nexus-text-muted">
                                Remaining gap: {{ $preferredSlotsTotal > 0 ? max(0, $preferredSlotsTotal - $assignmentCount).' slots' : 'n/a' }}.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-1 grid gap-4 xl:grid-cols-12">
        <div class="min-w-0 xl:col-span-8">
            <div class="nexus-panel h-full overflow-hidden" x-data="friendliesPanel()">
                <div class="nexus-panel__header items-center justify-between">
                    <div>
                        <h5 class="nexus-section-title">Unassigned friendlies</h5>
                        <small class="nexus-text-muted">No current target — fill gaps manually if needed.</small>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="war-plan-{{ $plan->id }}-friendlies-refresh" type="button" class="btn btn-outline btn-sm" @click="fetchFriendlies">
                            <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" /> Refresh
                        </button>
                        <div class="items-center gap-2" style="display: flex;" x-show="loading && !error" x-cloak>
                            <div class="loading loading-spinner loading-sm text-primary" role="status">
                                
                            </div>
                            <span class="text-sm nexus-text-muted">Loading friendlies…</span>
                        </div>
                        <span class="text-sm text-error" x-show="error" x-text="error"></span>
                        <button id="war-plan-{{ $plan->id }}-friendlies-retry" class="btn btn-outline btn-sm" type="button" x-show="error" @click="fetchFriendlies">Retry</button>
                    </div>
                </div>
                <div class="p-0">
                    <div class="p-3 flex flex-wrap gap-2 items-center">
                        <div class="flex w-full max-w-sm items-center gap-2">
                            <label for="war-plan-{{ $plan->id }}-friendlies-search" class="text-sm font-medium text-base-content/70">Search</label>
                            <input id="war-plan-{{ $plan->id }}-friendlies-search" type="search" class="input w-full" placeholder="Friendly, alliance" x-model.debounce.300ms="search">
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-box border border-base-300" id="assignments-table" x-show="!loading" x-cloak style="max-height: 520px; overflow-y: auto;">
                        <table class="table table-zebra align-middle [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-base-200/60" data-sortable="false">
                            <thead class="bg-base-200 sticky top-0" style="z-index: 1;">
                            <tr>
                                <th>Nation</th>
                                <th>Alliance</th>
                                <th>Wars</th>
                                <th>Activity</th>
                                <th class="text-right" data-sortable="false">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template x-if="!filteredUnassigned.length && !error">
                                <tr>
                                    <td colspan="5" class="text-center py-4 nexus-text-muted">Everyone is assigned.</td>
                                </tr>
                            </template>
                            <template x-for="friendly in filteredUnassigned" :key="friendly.id">
                                <tr>
                                    <td>
                                        <a :href="`https://politicsandwar.com/nation/id=${friendly.id}`" target="_blank" class="font-semibold" x-text="friendly.leader_name"></a>
                                        <div class="text-sm nexus-text-muted" x-text="friendly.nation_name"></div>
                                        <div class="text-sm">
                                            Cities <span x-text="friendly.num_cities ?? 0"></span>
                                            • Score <span x-text="formatNumber(friendly.score, 2)"></span>
                                        </div>
                                    </td>
                                    <td>
                                        <template x-if="friendly.alliance">
                                            <div>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-icon name="o-users" class="size-4 nexus-text-muted" aria-hidden="true" />
                                                    <a :href="`https://politicsandwar.com/alliance/id=${friendly.alliance.id}`" target="_blank" x-text="friendly.alliance.name"></a>
                                                </span>
                                                <div class="text-sm nexus-text-muted" x-text="friendly.alliance.acronym"></div>
                                            </div>
                                        </template>
                                        <template x-if="!friendly.alliance">
                                            <span class="nexus-text-muted">No alliance</span>
                                        </template>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost tooltip tooltip-left cursor-help" data-tip="Offensive / defensive wars" tabindex="0">
                                            <span x-text="friendly.offensive_wars_count ?? 0"></span>
                                            /
                                            <span x-text="friendly.defensive_wars_count ?? 0"></span>
                                        </span>
                                    </td>
                                    <td x-text="relativeTime(friendly.account_profile?.last_active)"></td>
                                    <td class="text-right">
                                        <button :id="`war-plan-{{ $plan->id }}-friendly-${friendly.id}-quick-assign-open`" class="btn btn-outline btn-primary btn-sm"
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
        <div class="xl:col-span-4">
            @include('admin.war-room.partials.score-guide')
        </div>
    </div>

    <dialog class="modal" id="importPlanModal" aria-label="Import war plan">
        <div class="modal-box max-w-3xl">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold">Import plan JSON</h3>
                    <p class="text-sm nexus-text-muted">Paste an exported payload to merge or replace current plan data.</p>
                </div>
                <form method="dialog">
                    <button id="war-plan-{{ $plan->id }}-import-close" class="btn btn-ghost btn-sm btn-circle" aria-label="Close import war plan dialog">✕</button>
                </form>
            </div>

            <form method="post" action="{{ route('admin.war-plans.import', $plan) }}" class="space-y-4">
                @csrf

                <label class="block space-y-2" for="war-plan-{{ $plan->id }}-import-payload">
                    <span class="text-sm font-medium">Payload</span>
                    <textarea id="war-plan-{{ $plan->id }}-import-payload" class="textarea min-h-56 w-full" name="payload" rows="10" placeholder="Paste exported JSON here" required
                              aria-describedby="war-plan-{{ $plan->id }}-import-payload-help{{ $errors->has('payload') ? ' war-plan-'.$plan->id.'-import-payload-error' : '' }}"
                              aria-invalid="{{ $errors->has('payload') ? 'true' : 'false' }}"></textarea>
                    @error('payload')
                        <span id="war-plan-{{ $plan->id }}-import-payload-error" class="text-xs text-error">{{ $message }}</span>
                    @enderror
                </label>

                <label class="label cursor-pointer justify-start gap-3" for="war-plan-{{ $plan->id }}-import-dry-run">
                    <input id="war-plan-{{ $plan->id }}-import-dry-run" class="checkbox checkbox-sm" type="checkbox" name="dry_run" value="1"
                           aria-invalid="{{ $errors->has('dry_run') ? 'true' : 'false' }}"
                           @if($errors->has('dry_run')) aria-describedby="war-plan-{{ $plan->id }}-import-dry-run-error" @endif>
                    <span class="">Dry run (preview diff only)</span>
                </label>
                @error('dry_run')
                    <span id="war-plan-{{ $plan->id }}-import-dry-run-error" class="text-xs text-error">{{ $message }}</span>
                @enderror

                <p id="war-plan-{{ $plan->id }}-import-payload-help" class="mb-0 text-sm nexus-text-muted">Imports honor the latest schema and override conflicting targets or assignments.</p>

                <div class="flex justify-end gap-2 pt-2">
                    <button id="war-plan-{{ $plan->id }}-import-cancel" type="button" class="btn btn-ghost" onclick="document.getElementById('importPlanModal').close()">Cancel</button>
                    <button id="war-plan-{{ $plan->id }}-import-submit" type="submit" class="btn btn-primary">Process import</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button id="war-plan-{{ $plan->id }}-import-backdrop-close" aria-label="Close import war plan dialog">close</button>
        </form>
    </dialog>

    @stack('modals')
@endsection

@push('scripts')
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
