@extends('layouts.main')

@php
    $activeCount = $subscriptions->filter(fn ($subscription) => $subscription->is_active && ! $subscription->expires_at?->isPast())->count();
    $unreadCount = $activity->whereNull('read_at')->count();
@endphp

@section('content')
    <div class="space-y-6" data-alert-center>
        <header class="flex flex-col gap-4 border-b border-base-300 pb-5 lg:flex-row lg:items-end lg:justify-between">
            <div class="space-y-2">
                <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Alert center</h1>
                <p class="max-w-3xl text-sm text-base-content/70">
                    Follow nation, alliance, and market changes without losing the delivery trail. Every match appears here first; Discord is an optional private destination.
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm" aria-label="Alert center status">
                <span class="badge badge-outline">{{ $activeCount }}/{{ $maxActiveAlerts }} active</span>
                <span class="badge badge-outline">{{ $unreadCount }} unread</span>
                <span class="badge {{ $notificationsEnabled && $discordLinked ? 'badge-success' : 'badge-warning' }} badge-outline">
                    Discord {{ $notificationsEnabled && $discordLinked ? 'ready' : 'needs attention' }}
                </span>
            </div>
        </header>

        @if(! $discordLinked)
            <div class="alert alert-warning" role="status">
                <span>Your Nexus account is not linked to Discord. Matches will still appear here, but private delivery will be unavailable.</span>
            </div>
        @elseif(! $notificationsEnabled)
            <div class="alert alert-info" role="status">
                <span>Discord delivery is paused globally. Existing alerts continue recording web activity.</span>
            </div>
        @else
            <div class="alert alert-success" role="status">
                <span>Discord delivery is enabled. Each subscription can still opt in or out independently.</span>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-error" role="alert">
                <div>
                    <div class="font-semibold">Review the highlighted alert settings.</div>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-sm">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(19rem,0.8fr)]">
            @include('user.alerts.partials.builder')
            @include('user.alerts.partials.settings')
        </div>

        @include('user.alerts.partials.subscriptions')
        @include('user.alerts.partials.activity')

        <datalist id="alert-timezones">
            @foreach($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const center = document.querySelector('[data-alert-center]');
            const builder = center?.querySelector('[data-alert-builder]');
            if (!builder) return;

            const steps = [...builder.querySelectorAll('[data-alert-step]')];
            const stepButtons = [...builder.querySelectorAll('[data-step-target]')];
            const typeInputs = [...builder.querySelectorAll('input[name="type"]')];
            const typePanels = [...builder.querySelectorAll('[data-type-panel]')];
            let currentStep = Number(builder.dataset.initialStep || 1);

            const selectedType = () => typeInputs.find((input) => input.checked)?.value || 'nation';
            const syncType = () => {
                const type = selectedType();
                typePanels.forEach((panel) => {
                    const active = panel.dataset.typePanel === type;
                    panel.hidden = !active;
                    panel.querySelectorAll('input, select').forEach((field) => {
                        field.disabled = !active;
                    });
                });
                builder.querySelectorAll('[data-review-type]').forEach((node) => {
                    node.textContent = type.charAt(0).toUpperCase() + type.slice(1);
                });
            };
            const showStep = (step) => {
                currentStep = Math.max(1, Math.min(4, step));
                steps.forEach((panel) => { panel.hidden = Number(panel.dataset.alertStep) !== currentStep; });
                stepButtons.forEach((button) => {
                    const active = Number(button.dataset.stepTarget) === currentStep;
                    button.classList.toggle('btn-primary', active);
                    button.classList.toggle('btn-ghost', !active);
                    button.setAttribute('aria-current', active ? 'step' : 'false');
                });
                builder.dataset.currentStep = String(currentStep);
            };

            typeInputs.forEach((input) => input.addEventListener('change', syncType));
            stepButtons.forEach((button) => button.addEventListener('click', () => showStep(Number(button.dataset.stepTarget))));
            builder.querySelectorAll('[data-step-next]').forEach((button) => button.addEventListener('click', () => showStep(currentStep + 1)));
            builder.querySelectorAll('[data-step-back]').forEach((button) => button.addEventListener('click', () => showStep(currentStep - 1)));

            syncType();
            showStep(currentStep);

            const timezone = center.querySelector('[data-account-timezone]');
            if (timezone?.dataset.proposeBrowserTimezone === 'true' && timezone.value === 'UTC') {
                const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (browserTimezone) timezone.value = browserTimezone;
            }

            const quietToggle = center.querySelector('[data-quiet-toggle]');
            const quietFields = [...center.querySelectorAll('[data-quiet-field]')];
            const syncQuietHours = () => quietFields.forEach((field) => { field.disabled = !quietToggle?.checked; });
            quietToggle?.addEventListener('change', syncQuietHours);
            syncQuietHours();
        });
    </script>
@endpush
