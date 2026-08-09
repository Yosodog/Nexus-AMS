@php
    $useSettingsOldInput = old('form_context') === 'settings';
    $quietStartValue = $useSettingsOldInput
        ? old('quiet_hours_start')
        : ($settings->quiet_hours_start ? substr($settings->quiet_hours_start, 0, 5) : '');
    $quietEndValue = $useSettingsOldInput
        ? old('quiet_hours_end')
        : ($settings->quiet_hours_end ? substr($settings->quiet_hours_end, 0, 5) : '');
    $quietEnabled = filled($quietStartValue) && filled($quietEndValue);
@endphp

<aside class="rounded-box border border-base-300 bg-base-100">
    <div class="border-b border-base-300 px-5 py-4">
        <h2 class="text-lg font-semibold">Delivery preferences</h2>
        <p class="mt-1 text-sm text-base-content/60">Applied to member alerts unless a subscription overrides the timezone.</p>
    </div>

    <form method="POST" action="{{ url('/user/alerts/settings') }}" class="space-y-5 p-5">
        @csrf
        @method('PUT')
        <input type="hidden" name="form_context" value="settings">

        <label class="flex cursor-pointer items-start justify-between gap-4 rounded-box bg-base-200/60 p-3" for="global-discord-enabled">
            <span>
                <span class="block text-sm font-medium">Private Discord delivery</span>
                <span class="block text-xs text-base-content/60">Global off switch for member alerts.</span>
            </span>
            <input type="hidden" name="discord_enabled" value="0">
            <input id="global-discord-enabled" type="checkbox" name="discord_enabled" value="1" class="toggle toggle-primary" @checked($useSettingsOldInput ? (bool) old('discord_enabled') : $settings->discord_enabled) @disabled(! $discordLinked)>
        </label>

        <div class="grid gap-2">
            <label class="text-sm font-medium" for="account-alert-timezone">Timezone</label>
            <input id="account-alert-timezone" name="timezone" value="{{ $useSettingsOldInput ? old('timezone', $settings->timezone) : $settings->timezone }}" maxlength="64" list="alert-timezones" class="input w-full" data-account-timezone data-propose-browser-timezone="{{ $settings->exists ? 'false' : 'true' }}" required>
            @if(! $settings->exists)
                <p class="text-xs text-base-content/60">Existing subscriptions remain on UTC until you save. Your browser timezone is proposed here.</p>
            @endif
        </div>

        <div class="space-y-3">
            <label class="flex cursor-pointer items-center justify-between gap-3" for="quiet-hours-enabled">
                <span class="text-sm font-medium">Quiet hours</span>
                <input id="quiet-hours-enabled" type="checkbox" class="toggle toggle-sm" data-quiet-toggle @checked($quietEnabled)>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <label class="text-xs font-medium" for="quiet-hours-start">Start</label>
                    <input id="quiet-hours-start" type="time" name="quiet_hours_start" value="{{ $quietStartValue }}" class="input w-full" data-quiet-field>
                </div>
                <div class="grid gap-2">
                    <label class="text-xs font-medium" for="quiet-hours-end">End</label>
                    <input id="quiet-hours-end" type="time" name="quiet_hours_end" value="{{ $quietEndValue }}" class="input w-full" data-quiet-field>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <div class="text-sm font-medium">Digest defaults</div>
            <div class="grid grid-cols-[1fr_1.35fr] gap-3">
                <div class="grid gap-2">
                    <label class="text-xs font-medium" for="default-digest-time">Time</label>
                    <input id="default-digest-time" type="time" name="default_digest_time" value="{{ $useSettingsOldInput ? old('default_digest_time', substr($settings->default_digest_time, 0, 5)) : substr($settings->default_digest_time, 0, 5) }}" class="input w-full" required>
                </div>
                <div class="grid gap-2">
                    <label class="text-xs font-medium" for="default-digest-weekday">Weekly day</label>
                    <select id="default-digest-weekday" name="default_digest_weekday" class="select w-full">
                        @foreach([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $day => $label)
                            <option value="{{ $day }}" @selected((int) ($useSettingsOldInput ? old('default_digest_weekday', $settings->default_digest_weekday) : $settings->default_digest_weekday) === $day)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <button class="btn btn-primary w-full">Save delivery preferences</button>
    </form>
</aside>
