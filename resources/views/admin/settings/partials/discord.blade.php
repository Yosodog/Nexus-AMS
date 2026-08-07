<div class="mb-5">
    <h2 class="nexus-section-title">Discord</h2>
    <p class="mt-1 max-w-3xl text-sm text-base-content/70">
        Configure account-link requirements, private workflow updates, managed city roles, and alliance departure alerts.
    </p>
</div>

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="discord-verification"
        title="Discord verification"
        description="Require users to connect an active Discord account after completing in-game verification."
        :status="$discordVerificationRequired ? 'Required' : 'Optional'"
        :status-class="$discordVerificationRequired ? 'badge-success' : 'badge-ghost'"
    >
        <form method="POST" action="{{ route('admin.settings.discord') }}" class="max-w-2xl space-y-5">
            @csrf
            <input type="hidden" name="require_discord_verification" value="0">

            <label class="flex cursor-pointer items-start gap-3">
                <input class="toggle toggle-primary mt-0.5" type="checkbox" id="requireDiscordVerification" name="require_discord_verification" value="1" @checked(old('require_discord_verification', $discordVerificationRequired))>
                <span>
                    <span class="block font-semibold">Require Discord verification</span>
                    <span class="mt-1 block text-sm text-base-content/70">Users without an active Discord link will be redirected to complete it.</span>
                </span>
            </label>

            <button class="btn btn-primary" type="submit">Save verification requirement</button>
        </form>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="discord-private-notifications"
        title="Private workflow notifications"
        description="Allow users to opt into minimal direct-message updates for supported workflow categories."
        :status="$discordPrivateNotificationsEnabled ? 'Enabled' : 'Disabled'"
        :status-class="$discordPrivateNotificationsEnabled ? 'badge-success' : 'badge-ghost'"
    >
        <form method="POST" action="{{ route('admin.settings.discord.private-notifications') }}" class="max-w-3xl space-y-5">
            @csrf
            <input type="hidden" name="discord_private_notifications_enabled" value="0">

            <label class="flex cursor-pointer items-start gap-3">
                <input class="toggle toggle-primary mt-0.5" type="checkbox" name="discord_private_notifications_enabled" value="1" @checked(old('discord_private_notifications_enabled', $discordPrivateNotificationsEnabled))>
                <span>
                    <span class="block font-semibold">Enable private workflow notifications</span>
                    <span class="mt-1 block text-sm leading-5 text-base-content/70">Messages contain only a status summary and a link back to {{ config('app.name') }}. Balances, resources, verification codes, notes, and denial reasons are never included.</span>
                </span>
            </label>

            <button class="btn btn-primary" type="submit">Save notification setting</button>
        </form>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="discord-city-tiers"
        title="City tier roles"
        description="Assign one bot-managed Discord role from each member's current city count."
        :status="$discordCityTierBucketSize . ' cities per tier'"
        :open="$errors->has('discord_city_tier_bucket_size')"
    >
        <form method="POST" action="{{ route('admin.settings.discord.city-tiers') }}" class="max-w-2xl space-y-5">
            @csrf

            <label class="block max-w-sm space-y-2">
                <span class="text-sm font-medium">Cities per tier</span>
                <input class="input w-full" type="number" name="discord_city_tier_bucket_size" min="1" max="100" value="{{ old('discord_city_tier_bucket_size', $discordCityTierBucketSize) }}" required>
                <span class="block text-xs leading-5 text-base-content/60">A value of 10 creates Cities 1–10, Cities 11–20, and so on. Enabled offshores are included; applicants and outsiders are excluded.</span>
            </label>

            <button class="btn btn-primary" type="submit">Save city tiers</button>
        </form>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="discord-departures"
        title="Alliance departure alerts"
        description="Send an alert when a non-applicant leaves any alliance in the configured membership group."
        :status="$discordDepartureEnabled ? 'Enabled' : 'Disabled'"
        :status-class="$discordDepartureEnabled ? 'badge-success' : 'badge-ghost'"
        :open="$errors->hasAny(['discord_alliance_departure_channel_id', 'discord_alliance_departure_enabled'])"
    >
        <form method="POST" action="{{ route('admin.settings.discord.departure') }}" class="max-w-2xl space-y-5">
            @csrf

            <label class="block space-y-2">
                <span class="text-sm font-medium">Channel ID</span>
                <input type="text" class="input w-full" id="discordAllianceDepartureChannelId" name="discord_alliance_departure_channel_id" value="{{ old('discord_alliance_departure_channel_id', $discordDepartureChannelId) }}" placeholder="123456789012345678">
                <span class="text-xs text-base-content/60">Leave blank to reuse the war alert channel.</span>
            </label>

            <input type="hidden" name="discord_alliance_departure_enabled" value="0">
            <label class="flex cursor-pointer items-center gap-3">
                <input class="toggle toggle-primary" type="checkbox" id="discordAllianceDepartureEnabled" name="discord_alliance_departure_enabled" value="1" @checked(old('discord_alliance_departure_enabled', $discordDepartureEnabled))>
                <span class="font-medium">Enable departure alerts</span>
            </label>

            <button class="btn btn-primary" type="submit">Save departure alerts</button>
        </form>
    </x-admin.settings-disclosure>
</div>
