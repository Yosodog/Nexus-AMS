<div class="mb-5">
    <h2 class="nexus-section-title">People & access</h2>
    <p class="mt-1 max-w-3xl text-sm text-base-content/70">
        Account lifecycle controls that affect access to {{ config('app.name') }}. Member activity policy remains with the member roster and is linked from the directory.
    </p>
</div>

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="account-inactivity"
        title="Account Inactivity Auto-Disable"
        description="Automatically disable user accounts after a configurable period without activity."
        :status="$userInactivityAutoDisableEnabled ? 'Enabled' : 'Disabled'"
        :status-class="$userInactivityAutoDisableEnabled ? 'badge-success' : 'badge-ghost'"
        :open="$errors->hasAny(['user_inactivity_auto_disable_enabled', 'user_inactivity_auto_disable_days'])"
    >
        <form method="POST" action="{{ route('admin.settings.account-inactivity-auto-disable') }}" class="max-w-2xl space-y-5">
            @csrf
            <input type="hidden" name="user_inactivity_auto_disable_enabled" value="0">

            <label class="flex cursor-pointer items-start gap-3">
                <input
                    class="toggle toggle-primary mt-0.5"
                    type="checkbox"
                    id="userInactivityAutoDisableEnabled"
                    name="user_inactivity_auto_disable_enabled"
                    value="1"
                    @checked(old('user_inactivity_auto_disable_enabled', $userInactivityAutoDisableEnabled))
                >
                <span>
                    <span class="block font-semibold">Enable automatic account disabling</span>
                    <span class="mt-1 block text-sm text-base-content/70">This affects {{ config('app.name') }} user access; it does not change a nation's in-game alliance position.</span>
                </span>
            </label>

            <label class="block max-w-sm space-y-2">
                <span class="text-sm font-medium">Inactivity threshold (days)</span>
                <input
                    type="number"
                    class="input w-full"
                    id="userInactivityAutoDisableDays"
                    name="user_inactivity_auto_disable_days"
                    min="1"
                    max="3650"
                    value="{{ old('user_inactivity_auto_disable_days', $userInactivityAutoDisableDays) }}"
                    required
                >
                <span class="text-xs text-base-content/60">Default: 90 days. Maximum: 10 years.</span>
            </label>

            <button class="btn btn-primary" type="submit">Save account inactivity policy</button>
        </form>
    </x-admin.settings-disclosure>
</div>
