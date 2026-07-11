@extends('layouts.main')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="space-y-2">
                <div class="flex flex-wrap gap-2 text-xs uppercase tracking-wide text-base-content/60">
                    <span class="badge badge-outline">Discord</span>
                    <span class="badge badge-outline">Alliance only</span>
                    <span class="badge badge-outline">{{ $subscriptions->filter(fn ($subscription) => $subscription->is_active && ! $subscription->expires_at?->isPast())->count() }}/{{ $maxActiveAlerts }} active</span>
                </div>
                <h1 class="text-3xl font-bold leading-tight sm:text-4xl">Custom alerts and watchlists</h1>
                <p class="max-w-3xl text-sm text-base-content/70">
                    Watch public nation, alliance, and market data. Nexus records the current value as a baseline, then sends a private Discord message when your selected condition changes.
                </p>
            </div>
            <a href="{{ route('user.settings') }}" class="btn btn-outline btn-sm">Discord notification settings</a>
        </div>

        @if(! $notificationsEnabled)
            <div class="alert alert-warning">
                <span>Alert delivery is off. Enable private Discord notifications and the “Custom alerts and watchlists” category in your settings before testing or receiving alerts.</span>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            <details class="rounded-box border border-base-300 bg-base-100 shadow-sm" @if(old('type') === 'nation') open @endif>
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create nation watch</summary>
                <form method="POST" action="{{ route('user.alerts.store') }}" class="grid gap-4 border-t border-base-300 p-5">
                    @csrf
                    <input type="hidden" name="type" value="nation">
                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Label <span class="text-base-content/50">(optional)</span></span>
                        <input name="name" value="{{ old('type') === 'nation' ? old('name') : '' }}" maxlength="100" class="input w-full" placeholder="Priority target">
                    </label>
                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Nation ID</span>
                        <input type="number" name="target_id" value="{{ old('type') === 'nation' ? old('target_id') : '' }}" min="1" class="input w-full" required>
                    </label>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">Events</legend>
                        @foreach($nationEvents as $event => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="events[]" value="{{ $event }}" class="checkbox checkbox-sm"
                                    @checked(old('type') === 'nation' && in_array($event, old('events', []), true))>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @include('user.alerts.partials.delivery-fields', ['formType' => 'nation'])
                    <button class="btn btn-primary">Create nation watch</button>
                </form>
            </details>

            <details class="rounded-box border border-base-300 bg-base-100 shadow-sm" @if(old('type') === 'alliance') open @endif>
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create alliance watch</summary>
                <form method="POST" action="{{ route('user.alerts.store') }}" class="grid gap-4 border-t border-base-300 p-5">
                    @csrf
                    <input type="hidden" name="type" value="alliance">
                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Label <span class="text-base-content/50">(optional)</span></span>
                        <input name="name" value="{{ old('type') === 'alliance' ? old('name') : '' }}" maxlength="100" class="input w-full" placeholder="Coalition partner">
                    </label>
                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Alliance ID</span>
                        <input type="number" name="target_id" value="{{ old('type') === 'alliance' ? old('target_id') : '' }}" min="1" class="input w-full" required>
                    </label>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">Events</legend>
                        @foreach($allianceEvents as $event => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="events[]" value="{{ $event }}" class="checkbox checkbox-sm"
                                    @checked(old('type') === 'alliance' && in_array($event, old('events', []), true))>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @include('user.alerts.partials.delivery-fields', ['formType' => 'alliance'])
                    <button class="btn btn-primary">Create alliance watch</button>
                </form>
            </details>

            <details class="rounded-box border border-base-300 bg-base-100 shadow-sm" @if(old('type') === 'market') open @endif>
                <summary class="cursor-pointer px-5 py-4 font-semibold">Create market threshold</summary>
                <form method="POST" action="{{ route('user.alerts.store') }}" class="grid gap-4 border-t border-base-300 p-5">
                    @csrf
                    <input type="hidden" name="type" value="market">
                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Label <span class="text-base-content/50">(optional)</span></span>
                        <input name="name" value="{{ old('type') === 'market' ? old('name') : '' }}" maxlength="100" class="input w-full" placeholder="Cheap steel">
                    </label>
                    <label class="grid gap-2">
                        <span class="text-sm font-medium">Resource</span>
                        <select name="resource" class="select w-full" required>
                            @foreach($resources as $resource => $label)
                                <option value="{{ $resource }}" @selected(old('type') === 'market' && old('resource') === $resource)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="grid grid-cols-[1fr_1.4fr] gap-3">
                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Direction</span>
                            <select name="direction" class="select w-full" required>
                                <option value="above" @selected(old('direction') === 'above')>At or above</option>
                                <option value="below" @selected(old('direction') === 'below')>At or below</option>
                            </select>
                        </label>
                        <label class="grid gap-2">
                            <span class="text-sm font-medium">Price</span>
                            <input type="number" name="threshold" value="{{ old('type') === 'market' ? old('threshold') : '' }}" min="0.01" max="1000000000" step="0.01" class="input w-full" required>
                        </label>
                    </div>
                    @include('user.alerts.partials.delivery-fields', ['formType' => 'market'])
                    <button class="btn btn-primary">Create price alert</button>
                </form>
            </details>
        </div>

        @if($errors->any())
            <div class="alert alert-error">
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-box border border-base-300 bg-base-100 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-300 px-5 py-4">
                <div>
                    <h2 class="text-xl font-semibold">Your alerts</h2>
                    <p class="text-sm text-base-content/60">Paused and expired alerts remain visible until you delete them.</p>
                </div>
                <span class="badge badge-outline">{{ $subscriptions->count() }} total</span>
            </div>

            @if($subscriptions->isEmpty())
                <div class="p-8 text-center text-sm text-base-content/60">No alerts yet. Create one above to establish its baseline.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Alert</th>
                                <th>Condition</th>
                                <th>Status</th>
                                <th>Last activity</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($subscriptions as $subscription)
                                @php
                                    $config = $subscription->config;
                                    $expired = $subscription->expires_at?->isPast() ?? false;
                                    $eventLabels = collect($config['events'] ?? [])->map(fn ($event) => $subscription->type->events()[$event] ?? $event)->join(', ');
                                    $condition = $subscription->type->value === 'market'
                                        ? ucfirst($config['resource']).' '.$config['direction'].' '.number_format($config['threshold'], 2)
                                        : $eventLabels;
                                @endphp
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $subscription->displayName() }}</div>
                                        <div class="text-xs text-base-content/60">{{ $subscription->type->label() }} · #{{ $subscription->id }}</div>
                                    </td>
                                    <td>
                                        <div class="max-w-sm text-sm">{{ $condition }}</div>
                                        <div class="mt-1 text-xs text-base-content/60">{{ $subscription->cooldown_minutes }} minute cooldown</div>
                                    </td>
                                    <td>
                                        @if($expired)
                                            <span class="badge badge-ghost">Expired</span>
                                        @elseif($subscription->is_active)
                                            <span class="badge badge-success badge-outline">Active</span>
                                        @else
                                            <span class="badge badge-warning badge-outline">Paused</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        <div>Checked {{ $subscription->last_evaluated_at?->diffForHumans() ?? 'not yet' }}</div>
                                        <div class="text-xs text-base-content/60">Triggered {{ $subscription->last_triggered_at?->diffForHumans() ?? 'never' }}</div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if(! $expired)
                                                <form method="POST" action="{{ route('user.alerts.status', $subscription) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="is_active" value="{{ $subscription->is_active ? 0 : 1 }}">
                                                    <button class="btn btn-xs btn-outline">{{ $subscription->is_active ? 'Pause' : 'Resume' }}</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('user.alerts.test', $subscription) }}">
                                                @csrf
                                                <button class="btn btn-xs btn-outline" @disabled(! $notificationsEnabled)>Test</button>
                                            </form>
                                            <form method="POST" action="{{ route('user.alerts.destroy', $subscription) }}" data-confirm="Delete this alert permanently?" data-confirm-title="Delete alert?" data-confirm-label="Delete alert" data-confirm-tone="error">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-xs btn-outline btn-error">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
