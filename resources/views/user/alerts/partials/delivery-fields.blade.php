@php
    $fieldPrefix = $fieldPrefix ?? 'alert';
    $subscription = $subscription ?? null;
    $formContext = $formContext ?? 'builder';
    $useOldInput = old('form_context') === $formContext
        || ($subscription === null && old('form_context') === null);
    $selectedMode = $useOldInput
        ? old('delivery_mode', $subscription?->delivery_mode?->value ?? 'immediate')
        : ($subscription?->delivery_mode?->value ?? 'immediate');
    $selectedTimezone = $useOldInput
        ? old('timezone', $subscription?->timezone ?? '')
        : ($subscription?->timezone ?? '');
    $selectedCooldown = $useOldInput
        ? (int) old('cooldown_minutes', $subscription?->cooldown_minutes ?? 60)
        : ($subscription?->cooldown_minutes ?? 60);
    $selectedExpiry = $useOldInput
        ? old('expires_at', $subscription?->expires_at?->format('Y-m-d\TH:i') ?? '')
        : ($subscription?->expires_at?->format('Y-m-d\TH:i') ?? '');
    $discordEnabled = $useOldInput
        ? (bool) old('discord_enabled', $subscription?->discord_enabled ?? false)
        : (bool) ($subscription?->discord_enabled ?? false);
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div class="grid gap-2">
        <label class="text-sm font-medium" for="{{ $fieldPrefix }}-delivery-mode">Delivery schedule</label>
        <select id="{{ $fieldPrefix }}-delivery-mode" name="delivery_mode" class="select w-full" data-alert-delivery-mode>
            <option value="immediate" @selected($selectedMode === 'immediate')>Immediately</option>
            <option value="daily" @selected($selectedMode === 'daily')>Daily digest</option>
            <option value="weekly" @selected($selectedMode === 'weekly')>Weekly digest</option>
        </select>
        <p class="text-xs text-base-content/60">Tests always send immediately and never alter this schedule.</p>
    </div>

    <div class="grid gap-2">
        <label class="text-sm font-medium" for="{{ $fieldPrefix }}-cooldown-minutes">Cooldown</label>
        <select id="{{ $fieldPrefix }}-cooldown-minutes" name="cooldown_minutes" class="select w-full">
            @foreach([15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 360 => '6 hours', 1440 => '1 day'] as $minutes => $label)
                <option value="{{ $minutes }}" @selected($selectedCooldown === $minutes)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid gap-2">
        <label class="text-sm font-medium" for="{{ $fieldPrefix }}-timezone">Schedule timezone <span class="text-base-content/60">(optional)</span></label>
        <input id="{{ $fieldPrefix }}-timezone" name="timezone" value="{{ $selectedTimezone }}" maxlength="64" list="alert-timezones" class="input w-full" placeholder="Use account default">
        <p class="text-xs text-base-content/60">Only overrides your account timezone for this alert.</p>
    </div>

    <div class="grid gap-2">
        <label class="text-sm font-medium" for="{{ $fieldPrefix }}-expires-at">Expires <span class="text-base-content/60">(optional)</span></label>
        <input id="{{ $fieldPrefix }}-expires-at" type="datetime-local" name="expires_at" value="{{ $selectedExpiry }}" class="input w-full">
    </div>
</div>

<label class="flex cursor-pointer items-start gap-3 rounded-box bg-base-200/60 p-3" for="{{ $fieldPrefix }}-discord-enabled">
    <input type="hidden" name="discord_enabled" value="0">
    <input id="{{ $fieldPrefix }}-discord-enabled" type="checkbox" name="discord_enabled" value="1" class="toggle toggle-primary mt-0.5" @checked($discordEnabled)>
    <span>
        <span class="block text-sm font-medium">Also deliver privately in Discord</span>
        <span class="block text-xs text-base-content/60">Web activity is always recorded. Private alerts never fall back to a public channel.</span>
    </span>
</label>
