@php
    $resourceList = $autoWithdrawResources ?? [];
    $settings = $autoWithdrawSettings ?? collect();
    $disabledByAdmin = isset($autoWithdrawEnabled) ? ! $autoWithdrawEnabled : false;
@endphp
<x-utils.card extraClasses="mb-2">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div class="flex items-center gap-2">
            <h2 class="text-xl font-bold">Auto Withdraw</h2>
            <div class="tooltip tooltip-bottom" data-tip="Before each turn, we check your nation. If a resource is under your threshold, we pull from the chosen account to top it up. One account is used for all enabled resources, there is a 24h cooldown per resource, and we only move what the account has. If admins pause the feature, nothing moves.">
                <span class="btn btn-circle btn-ghost btn-xs border border-base-300 text-base-content/70" aria-label="How Auto Withdraw works">
                    ?
                </span>
            </div>
        </div>
        <div class="text-sm text-right text-base-content/60">
            <p>Cooldown: 24h per resource</p>
            <p>Source: single selected account</p>
        </div>
    </div>

    <p class="text-sm text-base-content/70">
        Set a threshold and a pull amount for each resource. We will try to top you off right before a turn using your chosen account, up to the amount you set or the available balance.
    </p>

    @if($disabledByAdmin)
        <div class="alert alert-warning mt-3">
            <span class="font-semibold">Auto Withdraw disabled by admins.</span>
            Settings are read-only until re-enabled.
        </div>
    @endif

    <form method="POST" action="{{ route('auto-withdraw.update') }}" class="mt-4 space-y-4">
        @csrf
        <div class="overflow-x-auto rounded-xl border border-base-300">
            <table class="table w-full" data-sortable="false">
                <thead class="bg-base-200 text-sm">
                <tr>
                    <th>Resource</th>
                    <th>Enabled?</th>
                    <th>Account</th>
                    <th>Threshold</th>
                    <th>Withdraw amount</th>
                </tr>
                </thead>
                <tbody>
                @foreach($resourceList as $resource)
                    @php
                        $setting = $settings[$resource] ?? null;
                        $enabled = old("settings.$resource.enabled", $setting->enabled ?? false);
                        $accountId = old("settings.$resource.account_id", $setting->account_id ?? $accounts->first()?->id);
                        $threshold = old("settings.$resource.threshold", $setting->threshold ?? 0);
                        $withdrawAmount = old("settings.$resource.withdraw_amount", $setting->withdraw_amount ?? 0);
                    @endphp
                    <tr class="align-middle">
                        <td class="font-semibold capitalize">{{ $resource }}</td>
                        <td>
                            <input type="hidden" name="settings[{{ $resource }}][enabled]" value="0">
                            <input type="checkbox"
                                   class="toggle toggle-primary"
                                   aria-label="Enable {{ $resource }} auto withdraw"
                                   name="settings[{{ $resource }}][enabled]"
                                   value="1"
                                   @checked($enabled)
                                   @disabled($disabledByAdmin)>
                        </td>
                        <td>
                            <select name="settings[{{ $resource }}][account_id]"
                                    class="select w-full max-w-xs"
                                    aria-label="Source account for {{ $resource }} auto withdraw"
                                    @disabled($disabledByAdmin)>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((int) $accountId === $account->id)>
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="number"
                                   min="0"
                                   inputmode="numeric"
                                   aria-label="{{ ucfirst($resource) }} threshold"
                                   class="input w-full max-w-xs"
                                   name="settings[{{ $resource }}][threshold]"
                                   value="{{ $threshold }}"
                                   @disabled($disabledByAdmin)>
                        </td>
                        <td>
                            <input type="number"
                                   min="0"
                                   inputmode="numeric"
                                   aria-label="{{ ucfirst($resource) }} withdrawal amount"
                                   class="input w-full max-w-xs"
                                   name="settings[{{ $resource }}][withdraw_amount]"
                                   value="{{ $withdrawAmount }}"
                                   @disabled($disabledByAdmin)>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-end gap-3">
            <p class="text-sm text-base-content/60">Only runs when resources fall below thresholds and accounts have funds.</p>
            <button class="btn btn-primary" @disabled($disabledByAdmin)>
                Save auto withdraw
            </button>
        </div>
    </form>
</x-utils.card>
