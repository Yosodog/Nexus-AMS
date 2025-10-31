<div class="divider"></div>

<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4 gap-2">
    <div>
        <h1 class="text-2xl font-semibold mb-1 flex items-center gap-2">
            <i class="bi bi-cash-stack text-primary text-lg"></i>
            MMR Assistant
            @if ($mmrConfig && $mmrConfig->enabled)
                <span class="badge badge-outline badge-success">Enabled</span>
            @elseif($mmrEnabled)
                <span class="badge badge-outline badge-error">Disabled</span>
            @endif
        </h1>
        <p class="text-sm text-base-content/70">
            Automatically buy resources each turn using your Direct Deposit funds.
        </p>
    </div>

    @if ($mmrEnabled && $mmrConfig && $mmrConfig->enabled)
        {{-- Disable Assistant Form --}}
        <form method="POST" action="{{ route('mmra.update') }}" onsubmit="return confirm('Disable MMR Assistant?')">
            @csrf
            <input type="hidden" name="enabled" value="0">
            <input type="hidden" name="account_id" value="{{ $mmrConfig->account_id }}">
            @foreach($mmrResources as $resource)
                <input type="hidden" name="{{ $resource }}_pct" value="{{ $mmrConfig["{$resource}_pct"] }}">
            @endforeach
            <button type="submit" class="btn btn-sm btn-outline-error">
                <i class="bi bi-x-circle me-1"></i> Disable Assistant
            </button>
        </form>
    @endif
</div>

@if (!$mmrEnabled)
    <div class="rounded-lg bg-base-200 border border-base-300 p-6 text-center">
        <p class="text-lg font-medium text-base-content">
            The <strong>MMR Assistant</strong> system is currently <span class="text-warning font-semibold">disabled</span> by an administrator.
        </p>
        <p class="text-sm text-base-content/70 mt-2">Once re-enabled, this page will allow you to automate resource purchases from your Direct Deposit income.</p>
    </div>
@elseif (!$mmrConfig || !$mmrConfig->enabled)
    <form method="POST" action="{{ route('mmra.update') }}" class="space-y-4">
        @csrf

        <div>
            <label class="label font-semibold">Select the account to deposit purchased resources into</label>
            <select name="account_id" class="select select-bordered w-full" required>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
        </div>

        <input type="hidden" name="enabled" value="1" />

        <button type="submit" class="btn btn-primary">
            <i class="bi bi-lightning-charge me-1"></i> Enable MMR Assistant
        </button>
    </form>
@else
    <form method="POST" action="{{ route('mmra.update') }}" id="mmrAssistantForm" class="space-y-6">
        @csrf

        <div>
            <label class="label font-semibold">Deposit Resources Into</label>
            <select name="account_id" class="select select-bordered w-full" required>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected($account->id === $mmrConfig->account_id)>
                        {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead class="bg-base-300 text-base-content text-sm uppercase">
                <tr>
                    <th>Resource</th>
                    <th>Status</th>
                    <th>Surcharge</th>
                    <th>Price Estimate</th>
                    <th>% of Income</th>
                    <th>Estimated</th>
                </tr>
                </thead>
                <tbody>
                @foreach($mmrResources as $resource)
                    @php
                        $setting = $mmrSettings[$resource];
                        $ppu = $mmrPrices[$resource] ?? 0;
                        $percent = $mmrConfig["{$resource}_pct"] ?? 0;
                        $estimate = $ppu > 0 ? ($mmrAfterTaxIncome * ($percent / 100)) / $ppu : 0;
                    @endphp
                    <tr>
                        <td class="capitalize font-medium">{{ $resource }}</td>
                        <td>
                            @if(!$setting->enabled)
                                <span class="badge badge-warning tooltip" data-tip="Admins have disabled this resource. You can still set a % but it won’t be purchased.">
                                    Disabled
                                </span>
                            @else
                                <span class="badge badge-success">Enabled</span>
                            @endif
                        </td>
                        <td>{{ $setting->surcharge_pct }}%</td>
                        <td>${{ number_format($ppu, 2) }}</td>
                        <td>
                            <input type="number"
                                   aria-label="Percentage for {{ ucfirst($resource) }}"
                                   name="{{ $resource }}_pct"
                                   step="0.01"
                                   min="0"
                                   max="100"
                                   class="input input-sm input-bordered w-24 resource-input"
                                   data-ppu="{{ $ppu }}"
                                   value="{{ $percent }}">
                        </td>
                        <td>
                            <span class="badge badge-ghost estimate-badge" id="est-{{ $resource }}">
                                {{ number_format($estimate, 2) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="4" class="text-right font-bold">Total</td>
                    <td colspan="2">
                        <span id="totalPct" class="badge bg-neutral">0%</span>
                    </td>
                </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-save me-1"></i> Save Preferences
            </button>
        </div>
    </form>

    <div class="divider mt-10 mb-4">Recent MMR Assistant Logs</div>

    @if($mmrLogs->isEmpty())
        <p class="text-sm text-base-content/70">No MMR Assistant logs found yet.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-base-300">
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead class="bg-base-300 text-base-content text-sm uppercase">
                    <tr>
                        <th class="w-40">Date</th>
                        <th>Account</th>
                        <th class="w-32">Total Spent</th>
                        <th>Purchases</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($mmrLogs as $log)
                        @php
                            $resourceDetails = collect($mmrResources)
                                ->map(function ($resource) use ($log) {
                                    $amount = $log->$resource ?? 0;
                                    $ppu = $log->{"{$resource}_ppu"} ?? 0;

                                    return [
                                        'resource' => $resource,
                                        'label' => ucfirst($resource),
                                        'amount' => $amount,
                                        'ppu' => $ppu,
                                        'total' => $amount * $ppu,
                                    ];
                                })
                                ->filter(fn($row) => $row['amount'] > 0)
                                ->values();

                            $topResources = $resourceDetails->sortByDesc('total')->take(3);
                            $remainingCount = max($resourceDetails->count() - $topResources->count(), 0);
                        @endphp
                        <tr>
                            <td class="align-top text-sm text-base-content/80">{{ $log->created_at->format('M d, H:i') }}</td>
                            <td class="align-top">
                                <div class="font-medium">{{ optional($log->account)->name ?? 'Deleted Account' }}</div>
                                <div class="text-xs text-base-content/60">#{{ $log->account_id }}</div>
                            </td>
                            <td class="align-top font-semibold">${{ number_format($log->total_spent, 2) }}</td>
                            <td class="align-top">
                                @if($resourceDetails->isEmpty())
                                    <span class="text-sm text-base-content/70">No purchases recorded.</span>
                                @else
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($topResources as $item)
                                            <span class="badge badge-outline">
                                                {{ $item['label'] }} · ${{ number_format($item['total'], 2) }}
                                            </span>
                                        @endforeach

                                        @if($remainingCount > 0)
                                            <span class="badge badge-ghost">+{{ $remainingCount }} more</span>
                                        @endif
                                    </div>

                                    <details class="mt-2 rounded-lg border border-base-200 bg-base-200 px-4 py-2 text-sm">
                                        <summary class="cursor-pointer font-medium text-base-content">Full breakdown</summary>
                                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            @foreach($resourceDetails as $item)
                                                <div class="flex items-start justify-between gap-2">
                                                    <span class="capitalize font-medium text-base-content/90">{{ $item['resource'] }}</span>
                                                    <div class="text-right">
                                                        <div>{{ number_format($item['amount'], 2) }} @ ${{ number_format($item['ppu'], 2) }}</div>
                                                        <div class="text-xs text-base-content/60">=${{ number_format($item['total'], 2) }}</div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-base-300 bg-base-200 px-4 py-3">
                {{ $mmrLogs->links() }}
            </div>
        </div>
    @endif
@endif

@push('scripts')
    <script>
        function updateMMREstimates() {
            let total = 0;
            const afterTax = {{ $mmrAfterTaxIncome }};
            const badge = document.getElementById('totalPct');

            document.querySelectorAll('.resource-input').forEach(input => {
                const percent = parseFloat(input.value || 0);
                const ppu = parseFloat(input.dataset.ppu || 0);
                total += percent;

                const estimate = (percent > 0 && ppu > 0)
                    ? ((afterTax * (percent / 100)) / ppu)
                    : 0;

                const estLabel = document.getElementById('est-' + input.name.replace('_pct', ''));
                if (estLabel) {
                    estLabel.textContent = estimate.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
            });

            badge.textContent = total.toFixed(2) + '%';
            badge.classList.toggle('bg-error', total > 100 || total < 0);
            badge.classList.toggle('bg-success', total >= 0 && total <= 100);
            badge.classList.toggle('animate-pulse', total > 100);
        }

        document.querySelectorAll('.resource-input').forEach(input => {
            input.addEventListener('input', updateMMREstimates);
        });

        updateMMREstimates();
    </script>
@endpush
