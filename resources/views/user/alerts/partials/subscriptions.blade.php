<section class="rounded-box border border-base-300 bg-base-100">
    <div class="flex flex-col gap-2 border-b border-base-300 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold">Your subscriptions</h2>
            <p class="mt-1 text-sm text-base-content/60">Pause preserves history. Changing a watched target or condition establishes a fresh baseline.</p>
        </div>
        <span class="badge badge-outline">{{ $subscriptions->count() }} total</span>
    </div>

    @if($subscriptions->isEmpty())
        <div class="p-8 text-center">
            <div class="font-medium">No watchlists yet</div>
            <p class="mx-auto mt-1 max-w-md text-sm text-base-content/60">Create one above, preview the condition, and Nexus will establish the baseline after you save.</p>
        </div>
    @else
        <div class="divide-y divide-base-300">
            @foreach($subscriptions as $subscription)
                @php
                    $config = $subscription->config;
                    $expired = $subscription->expires_at?->isPast() ?? false;
                    $eventLabels = collect($config['events'] ?? [])->map(fn ($event) => $subscription->type->events()[$event] ?? $event)->join(', ');
                    $condition = $subscription->type->value === 'market'
                        ? ucfirst($config['resource']).' '.$config['direction'].' '.number_format($config['threshold'], 2)
                        : $eventLabels;
                    $statusLabel = $expired ? 'Expired' : ($subscription->is_active ? 'Active' : 'Paused');
                    $statusClass = $expired ? 'badge-ghost' : ($subscription->is_active ? 'badge-success' : 'badge-warning');
                    $editContext = 'subscription:'.$subscription->id;
                    $useEditOldInput = old('form_context') === $editContext;
                    $editValue = fn (string $key, mixed $default = null): mixed => $useEditOldInput ? old($key, $default) : $default;
                @endphp
                <article class="p-5 sm:p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="truncate text-base font-semibold">{{ $subscription->displayName() }}</h3>
                                <span class="badge {{ $statusClass }} badge-outline">{{ $statusLabel }}</span>
                                @if($subscription->discord_enabled)
                                    <span class="badge badge-outline">Discord on</span>
                                @endif
                            </div>
                            <div class="text-sm text-base-content/80">{{ $condition }}</div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-base-content/60">
                                <span>{{ ucfirst($subscription->delivery_mode->value) }}</span>
                                <span>{{ $subscription->cooldown_minutes }} minute cooldown</span>
                                <span>Matched {{ $subscription->last_triggered_at?->diffForHumans() ?? 'never' }}</span>
                                <span>Checked {{ $subscription->last_evaluated_at?->diffForHumans() ?? 'not yet' }}</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            @if(! $expired)
                                <form method="POST" action="{{ route('user.alerts.status', $subscription) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $subscription->is_active ? 0 : 1 }}">
                                    <button class="btn btn-sm btn-outline">{{ $subscription->is_active ? 'Pause' : 'Resume' }}</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('user.alerts.test', $subscription) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline">Test</button>
                            </form>
                            <form method="POST" action="{{ route('user.alerts.destroy', $subscription) }}" data-confirm="Delete this alert permanently?" data-confirm-title="Delete alert?" data-confirm-label="Delete alert" data-confirm-tone="error">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline btn-error">Delete</button>
                            </form>
                        </div>
                    </div>

                    @if(! $expired)
                        <details class="mt-4 rounded-box bg-base-200/50" @if($useEditOldInput) open @endif>
                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Edit condition and delivery</summary>
                            <form method="POST" action="{{ url('/user/alerts/'.$subscription->id) }}" class="space-y-4 border-t border-base-300 p-4">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="form_context" value="{{ $editContext }}">
                                <input type="hidden" name="type" value="{{ $subscription->type->value }}">

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2 sm:col-span-2">
                                        <label class="text-sm font-medium" for="subscription-{{ $subscription->id }}-name">Label</label>
                                        <input id="subscription-{{ $subscription->id }}-name" name="name" value="{{ $editValue('name', $subscription->name) }}" maxlength="100" class="input w-full">
                                    </div>

                                    @if($subscription->type->value === 'market')
                                        <div class="grid gap-2">
                                            <label class="text-sm font-medium" for="subscription-{{ $subscription->id }}-resource">Resource</label>
                                            <select id="subscription-{{ $subscription->id }}-resource" name="resource" class="select w-full">
                                                @foreach($resources as $resource => $label)
                                                    <option value="{{ $resource }}" @selected($editValue('resource', $config['resource']) === $resource)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div class="grid gap-2">
                                                <label class="text-sm font-medium" for="subscription-{{ $subscription->id }}-direction">Crosses</label>
                                                <select id="subscription-{{ $subscription->id }}-direction" name="direction" class="select w-full">
                                                    <option value="above" @selected($editValue('direction', $config['direction']) === 'above')>At or above</option>
                                                    <option value="below" @selected($editValue('direction', $config['direction']) === 'below')>At or below</option>
                                                </select>
                                            </div>
                                            <div class="grid gap-2">
                                                <label class="text-sm font-medium" for="subscription-{{ $subscription->id }}-threshold">Price</label>
                                                <input id="subscription-{{ $subscription->id }}-threshold" type="number" name="threshold" value="{{ $editValue('threshold', $config['threshold']) }}" min="0.01" max="1000000000" step="0.01" class="input w-full">
                                            </div>
                                        </div>
                                    @else
                                        <div class="grid gap-2">
                                            <label class="text-sm font-medium" for="subscription-{{ $subscription->id }}-target">{{ $subscription->type->label() }} ID</label>
                                            <input id="subscription-{{ $subscription->id }}-target" type="number" name="target_id" value="{{ $editValue('target_id', $config['target_id']) }}" min="1" class="input w-full">
                                        </div>
                                        <fieldset class="grid gap-2">
                                            <legend class="text-sm font-medium">Events</legend>
                                            <div class="grid gap-2 rounded-box bg-base-100 p-3">
                                                @foreach($subscription->type->events() as $event => $label)
                                                    <label class="flex items-center gap-2 text-sm">
                                                        <input type="checkbox" name="events[]" value="{{ $event }}" class="checkbox checkbox-sm" @checked(in_array($event, $editValue('events', $config['events'] ?? []), true))>
                                                        <span>{{ $label }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                </div>

                                @include('user.alerts.partials.delivery-fields', [
                                    'fieldPrefix' => 'subscription-'.$subscription->id,
                                    'formContext' => 'subscription:'.$subscription->id,
                                    'subscription' => $subscription,
                                ])

                                @if($subscription->type->value === 'market')
                                    <div class="grid max-w-xs gap-2">
                                        <label class="text-sm font-medium" for="subscription-{{ $subscription->id }}-rearm">Rearm buffer (%)</label>
                                        <input id="subscription-{{ $subscription->id }}-rearm" type="number" name="rearm_percent" value="{{ $editValue('rearm_percent', (float) $subscription->rearm_percent) }}" min="0.01" max="25" step="0.01" class="input w-full">
                                    </div>
                                @endif

                                <div class="flex justify-end">
                                    <button class="btn btn-primary btn-sm">Save changes</button>
                                </div>
                            </form>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
