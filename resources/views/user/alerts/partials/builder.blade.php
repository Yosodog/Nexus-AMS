@php
    $useBuilderOldInput = old('form_context') === null || old('form_context') === 'builder';
    $builderValue = fn (string $key, mixed $default = null): mixed => $useBuilderOldInput ? old($key, $default) : $default;
    $builderType = $builderValue('type', 'nation');
    $initialStep = is_array($preview) ? 4 : ($useBuilderOldInput && $errors->any() ? 2 : 1);
@endphp

<section class="rounded-box border border-base-300 bg-base-100" data-alert-builder data-initial-step="{{ $initialStep }}">
    <div class="border-b border-base-300 px-5 py-4 sm:px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold">Create an alert</h2>
                <p class="mt-1 text-sm text-base-content/60">Preview or test the exact behavior before saving a baseline.</p>
            </div>
            <nav class="flex flex-wrap gap-1" aria-label="Alert creation steps">
                @foreach([1 => 'Source', 2 => 'Condition', 3 => 'Delivery', 4 => 'Review'] as $step => $label)
                    <button type="button" class="btn btn-sm {{ $step === $initialStep ? 'btn-primary' : 'btn-ghost' }}" data-step-target="{{ $step }}">
                        {{ $step }}. {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>
    </div>

    <form method="POST" action="{{ route('user.alerts.store') }}" class="p-5 sm:p-6">
        @csrf
        <input type="hidden" name="form_context" value="builder">

        <div class="space-y-5" data-alert-step="1">
            <fieldset class="space-y-3">
                <legend class="text-base font-semibold">What should Nexus watch?</legend>
                <p class="text-sm text-base-content/60">Watchlists use public game data and are private to your Nexus account.</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach([
                        'nation' => ['Nation', 'Membership, vacation, beige, cities, and active wars'],
                        'alliance' => ['Alliance', 'Membership and treaty changes'],
                        'market' => ['Market', 'A resource crossing a price threshold'],
                    ] as $value => [$label, $description])
                        <label class="cursor-pointer rounded-box border border-base-300 p-4 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                            <span class="flex items-start gap-3">
                                <input type="radio" name="type" value="{{ $value }}" class="radio radio-primary radio-sm mt-0.5" @checked($builderType === $value)>
                                <span>
                                    <span class="block font-medium">{{ $label }}</span>
                                    <span class="mt-1 block text-xs leading-relaxed text-base-content/60">{{ $description }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="flex justify-end">
                <button type="button" class="btn btn-primary" data-step-next>Set the condition</button>
            </div>
        </div>

        <div class="space-y-5" data-alert-step="2">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <label class="text-sm font-medium" for="alert-name">Alert label <span class="text-base-content/60">(optional)</span></label>
                    <input id="alert-name" name="name" value="{{ $builderValue('name') }}" maxlength="100" class="input w-full" placeholder="Example: Cheap steel or priority nation">
                </div>

                <fieldset class="contents" data-type-panel="nation" @if($builderType !== 'nation') hidden @endif>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium" for="nation-target-id">Nation ID</label>
                        <input id="nation-target-id" type="number" name="target_id" value="{{ $builderValue('target_id') }}" min="1" class="input w-full" @disabled($builderType !== 'nation')>
                    </div>
                    <div class="grid gap-2">
                        <span class="text-sm font-medium">Events</span>
                        <div class="grid gap-2 rounded-box bg-base-200/60 p-3">
                            @foreach($nationEvents as $event => $label)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" class="checkbox checkbox-sm" @checked(in_array($event, $builderValue('events', []), true)) @disabled($builderType !== 'nation')>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </fieldset>

                <fieldset class="contents" data-type-panel="alliance" @if($builderType !== 'alliance') hidden @endif>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium" for="alliance-target-id">Alliance ID</label>
                        <input id="alliance-target-id" type="number" name="target_id" value="{{ $builderValue('target_id') }}" min="1" class="input w-full" @disabled($builderType !== 'alliance')>
                    </div>
                    <div class="grid gap-2">
                        <span class="text-sm font-medium">Events</span>
                        <div class="grid gap-2 rounded-box bg-base-200/60 p-3">
                            @foreach($allianceEvents as $event => $label)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" class="checkbox checkbox-sm" @checked(in_array($event, $builderValue('events', []), true)) @disabled($builderType !== 'alliance')>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </fieldset>

                <fieldset class="contents" data-type-panel="market" @if($builderType !== 'market') hidden @endif>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium" for="market-resource">Resource</label>
                        <select id="market-resource" name="resource" class="select w-full" @disabled($builderType !== 'market')>
                            @foreach($resources as $resource => $label)
                                <option value="{{ $resource }}" @selected($builderValue('resource') === $resource)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <label class="text-sm font-medium" for="market-direction">Crosses</label>
                            <select id="market-direction" name="direction" class="select w-full" @disabled($builderType !== 'market')>
                                <option value="above" @selected($builderValue('direction') === 'above')>At or above</option>
                                <option value="below" @selected($builderValue('direction') === 'below')>At or below</option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm font-medium" for="market-threshold">Price</label>
                            <input id="market-threshold" type="number" name="threshold" value="{{ $builderValue('threshold') }}" min="0.01" max="1000000000" step="0.01" class="input w-full" @disabled($builderType !== 'market')>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="flex justify-between gap-3">
                <button type="button" class="btn btn-ghost" data-step-back>Back</button>
                <button type="button" class="btn btn-primary" data-step-next>Choose delivery</button>
            </div>
        </div>

        <div class="space-y-5" data-alert-step="3">
            <div>
                <h3 class="font-semibold">How should this alert arrive?</h3>
                <p class="mt-1 text-sm text-base-content/60">Web activity is permanent for 30 days. Discord respects your quiet hours and digest schedule.</p>
            </div>
            @include('user.alerts.partials.delivery-fields', ['fieldPrefix' => 'builder', 'formContext' => 'builder'])
            <div class="grid gap-2 sm:max-w-xs">
                <label class="text-sm font-medium" for="builder-rearm-percent">Market rearm buffer</label>
                <div class="join">
                <input id="builder-rearm-percent" type="number" name="rearm_percent" value="{{ $builderValue('rearm_percent', 1) }}" min="0.01" max="25" step="0.01" class="input join-item w-full">
                    <span class="join-item flex items-center border border-base-300 bg-base-200 px-3 text-sm">%</span>
                </div>
                <p class="text-xs text-base-content/60">Prevents repeated market alerts while the price hovers at the threshold.</p>
            </div>
            <div class="flex justify-between gap-3">
                <button type="button" class="btn btn-ghost" data-step-back>Back</button>
                <button type="button" class="btn btn-primary" data-step-next>Review alert</button>
            </div>
        </div>

        <div class="space-y-5" data-alert-step="4">
            <div class="rounded-box bg-base-200/60 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Draft alert</div>
                        <div class="mt-1 text-lg font-semibold"><span data-review-type>{{ ucfirst($builderType) }}</span> watchlist</div>
                    </div>
                    <span class="badge badge-outline">No baseline yet</span>
                </div>
                <p class="mt-3 text-sm text-base-content/70">Preview validates the configuration without writing data. Test records a marked occurrence and delivery receipt without saving a subscription or changing a baseline.</p>
            </div>

            @if(is_array($preview))
                <div class="alert alert-info" role="status">
                    <div>
                        <div class="font-semibold">{{ $preview['name'] }}</div>
                        <div class="mt-1 text-sm">{{ $preview['condition'] }} · {{ ucfirst($preview['delivery']['mode']) }} delivery</div>
                    </div>
                </div>
            @endif

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <button type="button" class="btn btn-ghost" data-step-back>Back</button>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="submit" name="submit_action" value="preview" class="btn btn-outline">Preview</button>
                    <button type="submit" name="submit_action" value="test" class="btn btn-outline" @disabled(! $discordLinked)>Send test</button>
                    <button type="submit" name="submit_action" value="save" class="btn btn-primary" @disabled($activeCount >= $maxActiveAlerts)>Save alert</button>
                </div>
            </div>
        </div>
    </form>
</section>
