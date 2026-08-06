@extends('layouts.admin')

@section('title', 'Milcom settings')

@php
    $settings = $settings ?? [];
    $health = $health ?? [];
    $apiBase = $apiBase ?? url('/api/v1/milcom');
    $warTypes = $warTypes ?? [
        'raid' => 'Raid',
        'ordinary' => 'Ordinary',
        'attrition' => 'Attrition',
    ];
    $forumTags = old('forum_tag_ids', data_get($settings, 'forum_tag_ids', []));
    $forumTags = is_array($forumTags) ? implode(', ', $forumTags) : (string) $forumTags;
    $healthTone = static fn (string $status): string => match ($status) {
        'healthy', 'ready', 'connected' => 'nexus-status--success',
        'failed', 'unavailable', 'disconnected' => 'nexus-status--error',
        'degraded', 'stale', 'warning' => 'nexus-status--warning',
        default => 'nexus-status--neutral',
    };
@endphp

@section('content')
    <div data-milcom-app="settings" data-api-base="{{ $apiBase }}" class="contents">
        <x-header title="Milcom settings" separator use-h1>
            <x-slot:subtitle>Set up Discord delivery, counter monitoring, and default war details. Scoring rules cannot be changed here.</x-slot:subtitle>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'settings'])

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <form method="POST" action="{{ $apiBase }}/settings" class="grid gap-6" data-milcom-command="save-settings">
                @csrf
                <section class="nexus-panel" aria-labelledby="milcom-discord-settings-title">
                    <div class="nexus-panel__header">
                        <div>
                            <h2 id="milcom-discord-settings-title" class="nexus-section-title">Discord delivery</h2>
                            <p class="mt-1 text-sm text-base-content/65">After approval, Milcom creates one forum room for each staffed target.</p>
                        </div>
                        <span class="nexus-status {{ $healthTone((string) data_get($health, 'discord.status', 'unknown')) }}">{{ str((string) data_get($health, 'discord.status', 'Unknown'))->headline() }}</span>
                    </div>
                    <div class="nexus-panel__body grid gap-5 md:grid-cols-2">
                        <label class="block">
                            <span class="label px-0">Default war room forum ID</span>
                            <input type="text" inputmode="numeric" name="forum_id" value="{{ old('forum_id', data_get($settings, 'forum_id')) }}" class="input w-full" placeholder="123456789012345678" autocomplete="off">
                            <span class="mt-1 block text-xs nexus-text-muted">Used when a plan or counter does not have its own forum.</span>
                        </label>
                        <label class="block">
                            <span class="label px-0">Defense role ID</span>
                            <input type="text" inputmode="numeric" name="defense_role_id" value="{{ old('defense_role_id', data_get($settings, 'defense_role_id')) }}" class="input w-full" placeholder="123456789012345678" autocomplete="off">
                            <span class="mt-1 block text-xs nexus-text-muted">The bot can mention this role only when it is on the allowlist.</span>
                        </label>
                        <label class="block md:col-span-2">
                            <span class="label px-0">Forum tag IDs (optional)</span>
                            <input type="text" name="forum_tag_ids" value="{{ $forumTags }}" class="input w-full" placeholder="123456789012345678, 234567890123456789" autocomplete="off">
                            <span class="mt-1 block text-xs nexus-text-muted">Separate IDs with commas. Milcom uses only tags that exist in the selected forum.</span>
                        </label>
                    </div>
                </section>

                <section class="nexus-panel" aria-labelledby="milcom-monitoring-settings-title">
                    <div class="nexus-panel__header">
                        <div>
                            <h2 id="milcom-monitoring-settings-title" class="nexus-section-title">Counter monitoring</h2>
                            <p class="mt-1 text-sm text-base-content/65">Choose whether Milcom tracks incoming wars and shows alerts. It will never send a counter on its own.</p>
                        </div>
                    </div>
                    <div class="nexus-panel__body grid gap-4">
                        <label class="flex cursor-pointer items-start justify-between gap-4 rounded-md border border-base-300 p-4">
                            <span>
                                <span class="block font-semibold">Monitor incoming defensive wars</span>
                                <span class="mt-1 block text-sm text-base-content/65">Save every incoming war. If the attacked nation does not have enough plan coverage, Milcom suggests a counter team.</span>
                            </span>
                            <input type="hidden" name="counter_monitoring_enabled" value="0">
                            <input type="checkbox" name="counter_monitoring_enabled" value="1" class="toggle toggle-primary mt-1 shrink-0" @checked((bool) old('counter_monitoring_enabled', data_get($settings, 'counter_monitoring_enabled', true)))>
                        </label>
                        <div class="alert alert-info items-start" role="note">
                            <x-icon name="o-shield-check" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <p class="text-sm">Milcom waits for an officer to approve the team and send it to Discord.</p>
                        </div>
                    </div>
                </section>

                <section class="nexus-panel" aria-labelledby="milcom-declaration-defaults-title">
                    <div class="nexus-panel__header">
                        <div>
                            <h2 id="milcom-declaration-defaults-title" class="nexus-section-title">Declaration defaults</h2>
                            <p class="mt-1 text-sm text-base-content/65">You can change these values for a plan, counter, or target.</p>
                        </div>
                    </div>
                    <div class="nexus-panel__body grid gap-5 md:grid-cols-2">
                        <label class="block">
                            <span class="label px-0">Default war type</span>
                            <select name="default_war_type" class="select w-full">
                                @foreach ($warTypes as $value => $label)
                                    <option value="{{ $value }}" @selected(old('default_war_type', data_get($settings, 'default_war_type', 'ordinary')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="label px-0">Default reason</span>
                            <input type="text" name="default_war_reason" value="{{ old('default_war_reason', data_get($settings, 'default_war_reason')) }}" class="input w-full" maxlength="255" placeholder="Alliance defense operation">
                        </label>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <p class="mr-auto text-sm nexus-text-muted">These changes apply only to future Discord rooms.</p>
                    <a href="{{ url('/admin/milcom') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="o-check" class="size-5" aria-hidden="true" />
                        Save settings
                    </button>
                </div>
            </form>

            <aside class="grid gap-6 xl:sticky xl:top-4">
                <section class="nexus-panel" aria-labelledby="milcom-integration-health-title">
                    <div class="nexus-panel__header"><div><h2 id="milcom-integration-health-title" class="nexus-section-title">Service status</h2><p class="mt-1 text-sm text-base-content/65">Latest status from each service.</p></div></div>
                    <dl class="divide-y divide-base-300">
                        @foreach ([
                            ['key' => 'discord', 'label' => 'Discord bot'],
                            ['key' => 'forum', 'label' => 'War room forum'],
                            ['key' => 'subscriptions', 'label' => 'P&W subscriptions'],
                            ['key' => 'counter_queue', 'label' => 'Counter queue'],
                        ] as $integration)
                            @php
                                $integrationStatus = (string) data_get($health, $integration['key'].'.status', 'unknown');
                            @endphp
                            <div class="flex items-center justify-between gap-3 p-4">
                                <div><dt class="font-semibold">{{ $integration['label'] }}</dt><dd class="mt-1 text-xs nexus-text-muted">{{ data_get($health, $integration['key'].'.detail', 'No status reported') }}</dd></div>
                                <span class="nexus-status {{ $healthTone($integrationStatus) }} shrink-0">{{ str($integrationStatus)->headline() }}</span>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="nexus-panel" aria-labelledby="milcom-doctrine-title">
                    <div class="nexus-panel__body">
                        <div class="flex items-start gap-3">
                            <span class="inline-grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><x-icon name="o-lock-closed" class="size-5" aria-hidden="true" /></span>
                            <div>
                                <h2 id="milcom-doctrine-title" class="font-semibold">Scoring rules</h2>
                                <p class="mt-1 text-sm leading-6 text-base-content/65">Milcom uses one fixed set of rules for team scoring, war range, and offensive slots. These rules change only when Nexus is updated.</p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>

        <div class="hidden alert alert-error items-start" role="alert" data-milcom-feedback><x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" /><div><div class="font-semibold" data-milcom-feedback-title>Could not save settings</div><p class="text-sm" data-milcom-feedback-message>Check the marked fields and try again.</p></div></div>
        <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection
