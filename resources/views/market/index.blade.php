@extends('layouts.main')

@section('content')
    <div class="space-y-6">
        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow-md">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Alliance Market</p>
                    <h1 class="text-2xl font-bold">Sell resources at alliance buy prices</h1>
                    <p class="text-sm text-base-content/70">Prices refresh hourly from the 24h average with admin adjustments.</p>
                </div>
                <div class="rounded-xl bg-primary/10 border border-primary/20 px-4 py-3 text-primary">
                    <p class="text-xs uppercase">Available Resources</p>
                    <p class="text-xl font-bold">{{ count($marketResources) }}</p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($marketResources as $resource)
                @php
                    $isSoldOut = (float) $resource['buy_cap_remaining'] <= 0;
                @endphp
                <div class="rounded-2xl bg-base-100 border border-base-300 p-5 shadow-sm flex flex-col gap-4 {{ $isSoldOut ? 'opacity-60' : '' }}">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-xs uppercase text-base-content/60">{{ str_replace('_', ' ', $resource['resource']) }}</p>
                            <div class="flex items-center gap-2">
                                <span class="text-2xl font-bold text-primary">
                                    ${{ number_format($resource['final_price'], 4) }}
                                </span>
                                <span class="tooltip tooltip-bottom" data-tip="24h avg ${{ number_format($resource['base_price'], 4) }}">
                                    <span class="badge badge-ghost">24h avg</span>
                                </span>
                            </div>
                        </div>
                        <span class="badge {{ $isSoldOut ? 'badge-ghost' : ($resource['adjustment_percent'] >= 0 ? 'badge-success' : 'badge-error') }}">
                            @if ($isSoldOut)
                                Sold out
                            @else
                                {{ $resource['adjustment_percent'] >= 0 ? '+' : '' }}{{ number_format($resource['adjustment_percent'], 2) }}%
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm text-base-content/70">
                        <span>Remaining cap</span>
                        <span class="font-semibold text-base-content">
                            {{ number_format($resource['buy_cap_remaining'], $resource['buy_cap_remaining'] >= 1000 ? 0 : 2) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rounded-2xl bg-base-100 border border-base-300 p-6 text-center text-base-content/70">
                    No resources are currently buyable. Check back later.
                </div>
            @endforelse
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-2xl bg-base-100 border border-base-300 p-6 shadow-sm">
                <h2 class="text-lg font-semibold mb-4">Sell to the alliance</h2>
                <form
                    method="POST"
                    action="{{ route('market.sell') }}"
                    class="space-y-4"
                    x-data="marketForm(
                        @js($priceMap),
                        @js($accountBalances),
                        @js($resourceLabels),
                        @js(old('resource', $resourceOptions[0] ?? '')),
                        @js(old('amount', '')),
                        @js(old('account_id', ''))
                    )"
                    x-on:submit="isSubmitting = true"
                >
                    @csrf
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="form-control w-full">
                            <div class="label">
                                <span class="label-text">Account</span>
                            </div>
                            <select name="account_id" class="select select-bordered w-full" x-model="accountId" required>
                                <option value="" disabled>Select an account</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('account_id')
                                <p class="text-xs text-error mt-2">{{ $message }}</p>
                            @enderror
                        </label>

                        <label class="form-control w-full">
                            <div class="label">
                                <span class="label-text">Resource</span>
                            </div>
                            <select name="resource" class="select select-bordered w-full" x-model="resource" required>
                                <option value="" disabled>Select a resource</option>
                                @foreach ($marketResources as $resource)
                                    @php
                                        $isSoldOut = (float) $resource['buy_cap_remaining'] <= 0;
                                    @endphp
                                    <option value="{{ $resource['resource'] }}"
                                            @selected(old('resource') === $resource['resource'])
                                            @disabled($isSoldOut)>
                                        {{ str_replace('_', ' ', $resource['resource']) }}{{ $isSoldOut ? ' (Sold out)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('resource')
                                <p class="text-xs text-error mt-2">{{ $message }}</p>
                            @enderror
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="form-control w-full">
                            <div class="label">
                                <span class="label-text">Amount</span>
                            </div>
                            <input
                                type="number"
                                name="amount"
                                min="1"
                                step="0.01"
                                class="input input-bordered w-full"
                                placeholder="Enter amount"
                                x-model="amount"
                                required
                            />
                            <div class="label">
                                <span class="label-text-alt text-base-content/60">Minimum sale: 1 unit</span>
                            </div>
                            @error('amount')
                                <p class="text-xs text-error mt-2">{{ $message }}</p>
                            @enderror
                        </label>

                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase text-base-content/60">Estimated payout</p>
                            <p class="text-2xl font-bold text-primary" x-text="formatCurrency(payout)">$0.00</p>
                            <p class="text-xs text-base-content/60 mt-1">
                                Rate: <span x-text="`$${finalPrice.toFixed(4)}`">$0.0000</span>
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-base-300 bg-base-200/40 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs uppercase text-base-content/60">Account balances</p>
                            <p class="text-xs text-base-content/50" x-show="!accountId">Select an account to view balances.</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" x-show="accountId">
                            <template x-for="(label, key) in resourceLabels" :key="key">
                                <div class="flex items-center justify-between rounded-lg bg-base-100 border border-base-300 px-3 py-2">
                                    <span class="text-xs uppercase text-base-content/60" x-text="label"></span>
                                    <span class="text-sm font-semibold text-base-content"
                                          x-text="formatAmount(accountBalances[accountId]?.[key])"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-full md:w-auto" x-bind:disabled="isSubmitting">
                        <span x-show="!isSubmitting">Sell to Alliance</span>
                        <span x-show="isSubmitting">Processing...</span>
                    </button>
                </form>
            </div>

            <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow-sm">
                <h3 class="text-lg font-semibold mb-3">Recent transactions</h3>
                <div class="space-y-3">
                    @forelse ($recentTransactions as $transaction)
                        <div class="flex items-center justify-between border border-base-200 rounded-xl px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-base-content">
                                    {{ str_replace('_', ' ', $transaction->resource) }} · {{ number_format($transaction->amount, 2) }}
                                </p>
                                <p class="text-xs text-base-content/60">{{ $transaction->created_at->format('M d, Y H:i') }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-primary">${{ number_format($transaction->money_paid, 2) }}</p>
                                <p class="text-xs text-base-content/60">${{ number_format($transaction->final_price, 4) }} / unit</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-base-content/60">No market sales yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function marketForm(prices, accountBalances, resourceLabels, resource, amount, accountId) {
            return {
                prices,
                accountBalances,
                resourceLabels,
                resource,
                amount,
                accountId,
                isSubmitting: false,
                get finalPrice() {
                    return this.prices[this.resource]?.final_price || 0;
                },
                get payout() {
                    const amount = parseFloat(this.amount || 0);
                    return amount > 0 ? amount * this.finalPrice : 0;
                },
                formatAmount(value) {
                    return new Intl.NumberFormat(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(value || 0);
                },
                formatCurrency(value) {
                    const formatted = new Intl.NumberFormat(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(value || 0);
                    return `$${formatted}`;
                }
            };
        }
    </script>
@endpush
