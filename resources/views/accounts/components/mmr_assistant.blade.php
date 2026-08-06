<div class="divider"></div>

<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4 gap-2">
    <div>
        <h1 class="text-2xl font-semibold mb-1 flex items-center gap-2">
            <x-icon name="o-banknotes" class="size-5 text-primary" aria-hidden="true" />
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
        <form method="POST" action="{{ route('mmra.update') }}" data-confirm="Disable MMR Assistant? Existing settings remain available if you enable it again." data-confirm-title="Disable MMR Assistant?" data-confirm-label="Disable assistant">
            @csrf
            <input type="hidden" name="enabled" value="0">
            <input type="hidden" name="auto_cover_resource_deficits" value="{{ (int) $mmrConfig->auto_cover_resource_deficits }}">
            <input type="hidden" name="account_id" value="{{ $mmrConfig->account_id }}">
            @foreach($mmrResources as $resource)
                <input type="hidden" name="{{ $resource }}_pct" value="{{ $mmrConfig["{$resource}_pct"] }}">
            @endforeach
            <button type="submit" class="btn btn-sm btn-outline btn-error">
                <x-icon name="o-x-circle" class="size-4" aria-hidden="true" />
                Disable assistant
            </button>
        </form>
    @endif
</div>

@if (!$mmrEnabled)
    <div class="rounded-lg bg-base-200 border border-base-300 p-6 text-center">
        <p class="text-lg font-medium text-base-content">
            The <strong>MMR Assistant</strong> system is currently <span class="text-warning font-semibold">disabled</span> by an administrator.
        </p>
        <p class="text-sm text-base-content/70 mt-2">When admins turn it back on, you can automate resource purchases from your Direct Deposit income.</p>
    </div>
@elseif (!$mmrConfig || !$mmrConfig->enabled)
    <form method="POST" action="{{ route('mmra.update') }}" class="space-y-4">
        @csrf

        <div>
            <label class="label font-semibold">Choose where purchased resources go</label>
            <select name="account_id" class="select w-full" required>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
        </div>

        <input type="hidden" name="enabled" value="1" />

        <input type="hidden" name="auto_cover_resource_deficits" value="0">
        <label for="mmrAutoCoverToggle" class="flex cursor-pointer items-start gap-3 rounded-lg border border-base-300 bg-base-200 p-4">
            <input
                id="mmrAutoCoverToggle"
                type="checkbox"
                name="auto_cover_resource_deficits"
                value="1"
                class="toggle toggle-primary mt-0.5"
            >
            <span>
                <span class="block font-semibold">Automatically cover projected resource deficits</span>
                <span class="mt-1 block text-sm text-base-content/70">
                    Each turn, use your available Direct Deposit cash to replace recurring resources consumed by your current build.
                </span>
            </span>
        </label>

        <button type="submit" class="btn btn-primary">
            <x-icon name="o-bolt" class="size-4" aria-hidden="true" />
            Enable MMR assistant
        </button>
    </form>
@else
    <form method="POST" action="{{ route('mmra.update') }}" id="mmrAssistantForm" class="space-y-6">
        @csrf

        @php
            $autoCoverResourceDeficits = (bool) $mmrConfig->auto_cover_resource_deficits;
            $autoPlan = $mmrAutoPlan ?? [];
            $autoPlanStatus = $autoPlan['status'] ?? 'projection_unavailable';
            $autoUnavailableResources = collect($autoPlan['unavailable_resources'] ?? [])
                ->map(fn($resource) => ucfirst($resource))
                ->join(', ');
            $autoEstimatedPurchases = collect($autoPlan['lines'] ?? [])
                ->map(fn($line, $resource) => [
                    'resource' => $resource,
                    'quantity' => (float) ($line['qty'] ?? 0),
                    'target_quantity' => (float) ($line['target_qty'] ?? 0),
                    'spend' => (float) ($line['spend'] ?? 0),
                    'ppu' => (float) ($line['ppu'] ?? 0),
                ])
                ->filter(fn($line) => $line['quantity'] > 0)
                ->values();
        @endphp

        <div>
            <label class="label font-semibold">Deposit resources into</label>
            <select name="account_id" class="select w-full" required>
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected($account->id === $mmrConfig->account_id)>
                        {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="space-y-3">
            <input type="hidden" name="auto_cover_resource_deficits" value="0">
            <label for="mmrAutoCoverToggle" class="flex cursor-pointer items-start gap-3 rounded-lg border border-base-300 bg-base-200 p-4">
                <input
                    id="mmrAutoCoverToggle"
                    type="checkbox"
                    name="auto_cover_resource_deficits"
                    value="1"
                    class="toggle toggle-primary mt-0.5"
                    @checked($autoCoverResourceDeficits)
                >
                <span>
                    <span class="block font-semibold">Automatically cover projected resource deficits</span>
                    <span class="mt-1 block max-w-3xl text-sm text-base-content/70">
                        {{ config('app.name') }} will buy up to one turn of recurring resource shortfalls from your current build. If cash is limited, every available deficit is covered proportionally.
                        Your manual percentages are preserved for whenever you turn this option off.
                    </span>
                </span>
            </label>

            <div id="mmrAutoProjection" class="rounded-lg border border-base-300 bg-base-200 p-4 {{ $autoCoverResourceDeficits ? '' : 'hidden' }}" aria-live="polite">
                @if($autoPlanStatus === 'projection_unavailable')
                    <div role="status" class="flex items-start gap-3 text-warning">
                        <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-semibold">A recent economy projection is not available.</p>
                            <p class="mt-1 text-sm text-base-content/70">Automatic purchases will pause and your cash will be deposited normally until a fresh projection is ready.</p>
                        </div>
                    </div>
                @elseif($autoPlanStatus === 'no_deficits')
                    <div role="status" class="flex items-start gap-3 text-success">
                        <x-icon name="o-check-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-semibold">No recurring resource deficits are projected.</p>
                            <p class="mt-1 text-sm text-base-content/70">No cash would be withheld based on the latest synchronized build.</p>
                        </div>
                    </div>
                @elseif($autoPlanStatus === 'no_purchasable_deficits')
                    <div role="status" class="flex items-start gap-3 text-warning">
                        <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-semibold">Projected deficits cannot currently be purchased.</p>
                            <p class="mt-1 text-sm text-base-content/70">The required resources are disabled by an administrator or do not have usable pricing.</p>
                        </div>
                    </div>
                @else
                    <div class="flex flex-wrap gap-x-8 gap-y-3">
                        <div>
                            <span class="block text-xs font-medium text-base-content/60">Estimated spend</span>
                            <span class="font-semibold">${{ number_format((float) ($autoPlan['total_spend'] ?? 0), 2) }}</span>
                        </div>
                        <div>
                            <span class="block text-xs font-medium text-base-content/60">Purchasable deficit cost</span>
                            <span class="font-semibold">${{ number_format((float) ($autoPlan['target_spend'] ?? 0), 2) }}</span>
                        </div>
                        <div>
                            <span class="block text-xs font-medium text-base-content/60">Purchasable coverage</span>
                            <span class="font-semibold">{{ number_format((float) ($autoPlan['coverage_pct'] ?? 0), 2) }}%</span>
                        </div>
                    </div>

                    <div class="mt-4 border-t border-base-300 pt-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold">Estimated purchases this turn</h3>
                            <span class="text-xs text-base-content/60">Based on the preview income below</span>
                        </div>

                        @if($autoEstimatedPurchases->isEmpty())
                            <p class="mt-2 text-sm text-base-content/70">No resource purchases are estimated with the currently available preview income.</p>
                        @else
                            <div class="mt-2 overflow-x-auto">
                                <table class="table table-sm w-full" data-sortable="false">
                                    <caption class="sr-only">Estimated automatic resource purchases for one turn</caption>
                                    <thead>
                                    <tr class="text-xs text-base-content/60">
                                        <th>Resource</th>
                                        <th class="text-right">Estimated quantity</th>
                                        <th class="text-right">Estimated cost</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($autoEstimatedPurchases as $purchase)
                                        <tr>
                                            <td class="capitalize font-medium">{{ $purchase['resource'] }}</td>
                                            <td class="text-right">
                                                <span class="font-semibold">{{ number_format($purchase['quantity'], 2) }}</span>
                                                <span class="block text-xs text-base-content/60">of {{ number_format($purchase['target_quantity'], 2) }} needed</span>
                                            </td>
                                            <td class="text-right">
                                                <span class="font-semibold">${{ number_format($purchase['spend'], 2) }}</span>
                                                <span class="block text-xs text-base-content/60">at ${{ number_format($purchase['ppu'], 2) }} each</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <p class="mt-3 text-sm text-base-content/70">
                        Previewed using your latest after-tax Direct Deposit income of ${{ number_format($mmrAfterTaxIncome, 2) }}
                        @if($autoPlan['projection_calculated_at'] ?? null)
                            and the economy projection from {{ $autoPlan['projection_calculated_at']->diffForHumans() }}
                        @endif
                        . Each live purchase is recalculated using that turn's actual income and current MMR prices.
                    </p>

                    @if($mmrAfterTaxIncome <= 0)
                        <p class="mt-2 text-sm text-warning">No previous cash deposit is available for this preview, so the displayed coverage is 0%. Live turns with cash can still make purchases.</p>
                    @endif
                @endif

                @if($autoUnavailableResources !== '')
                    <p class="mt-3 text-sm text-warning">
                        Not currently purchasable: {{ $autoUnavailableResources }}.
                    </p>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full" data-sortable="false">
                <thead class="bg-base-300 text-base-content text-sm uppercase">
                <tr>
                    <th>Resource</th>
                    <th>Status</th>
                    <th>Surcharge</th>
                    <th>Price Estimate</th>
                    <th>Manual %</th>
                    <th>Purchase / Turn</th>
                </tr>
                </thead>
                <tbody>
                @foreach($mmrResources as $resource)
                    @php
                        $setting = $mmrSettings[$resource];
                        $ppu = $mmrPrices[$resource] ?? 0;
                        $percent = $mmrConfig["{$resource}_pct"] ?? 0;
                        $estimate = $ppu > 0 ? ($mmrAfterTaxIncome * ($percent / 100)) / $ppu : 0;
                        $autoLine = $autoPlan['lines'][$resource] ?? [];
                        $autoQuantity = (float) ($autoLine['qty'] ?? 0);
                        $autoTargetQuantity = (float) ($autoLine['target_qty'] ?? 0);
                    @endphp
                    <tr>
                        <td class="capitalize font-medium">{{ $resource }}</td>
                        <td>
                            @if(!$setting->enabled)
                                <span class="badge badge-warning tooltip" data-tip="Admins have disabled this resource. You can still set a %, but it will not be purchased.">
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
                                   class="input input-sm w-24 resource-input {{ $autoCoverResourceDeficits ? 'opacity-50' : '' }}"
                                   data-ppu="{{ $ppu }}"
                                   value="{{ $percent }}"
                                   aria-disabled="{{ $autoCoverResourceDeficits ? 'true' : 'false' }}"
                                   @readonly($autoCoverResourceDeficits)>
                        </td>
                        <td>
                            <span class="badge badge-ghost estimate-badge manual-estimate {{ $autoCoverResourceDeficits ? 'hidden' : '' }}" id="est-{{ $resource }}">
                                {{ number_format($estimate, 2) }}
                            </span>
                            <span class="auto-estimate {{ $autoCoverResourceDeficits ? '' : 'hidden' }}">
                                @if($autoTargetQuantity > 0)
                                    <span class="font-medium">{{ number_format($autoQuantity, 2) }}</span>
                                    <span class="ml-1 text-xs text-base-content/60">of {{ number_format($autoTargetQuantity, 2) }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr id="manualTotalRow" class="{{ $autoCoverResourceDeficits ? 'hidden' : '' }}">
                    <td colspan="4" class="text-right font-bold">Total</td>
                    <td colspan="2">
                        <span id="totalPct" class="badge badge-neutral">0%</span>
                    </td>
                </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-success">
                <x-icon name="o-check" class="size-4" aria-hidden="true" />
                Save preferences
            </button>
        </div>
    </form>

    <div class="divider mt-10 mb-4">Recent MMR Assistant logs</div>

    @if($mmrLogs->isEmpty())
        <p class="text-sm text-base-content/70">No MMR Assistant logs found yet.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-base-300">
            <div class="overflow-x-auto">
                <table class="table table-sm w-full" data-sortable="false">
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
                                @if($log->allocation_mode === \App\Models\MMRAssistantPurchase::ALLOCATION_MODE_AUTOMATIC)
                                    <span class="badge badge-sm badge-outline mt-1">Auto coverage</span>
                                @endif
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
            if (!badge) {
                return;
            }

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
            badge.classList.remove('badge-neutral');
            badge.classList.toggle('badge-error', total > 100 || total < 0);
            badge.classList.toggle('badge-success', total >= 0 && total <= 100);
            badge.classList.toggle('animate-pulse', total > 100);
        }

        function initMMRAssistant() {
            const inputs = document.querySelectorAll('.resource-input');
            const autoToggle = document.getElementById('mmrAutoCoverToggle');

            inputs.forEach(input => {
                if (input.dataset.bound === 'true') {
                    return;
                }

                input.dataset.bound = 'true';
                input.addEventListener('input', updateMMREstimates);
            });

            if (autoToggle && autoToggle.dataset.bound !== 'true') {
                autoToggle.dataset.bound = 'true';
                autoToggle.addEventListener('change', updateMMRAutomaticMode);
            }

            if (inputs.length && document.getElementById('totalPct')) {
                updateMMREstimates();
            }

            updateMMRAutomaticMode();
        }

        function updateMMRAutomaticMode() {
            const autoToggle = document.getElementById('mmrAutoCoverToggle');
            if (!autoToggle) {
                return;
            }

            const automatic = autoToggle.checked;
            const projection = document.getElementById('mmrAutoProjection');
            const manualTotalRow = document.getElementById('manualTotalRow');

            projection?.classList.toggle('hidden', !automatic);
            manualTotalRow?.classList.toggle('hidden', automatic);

            document.querySelectorAll('.resource-input').forEach(input => {
                input.readOnly = automatic;
                input.setAttribute('aria-disabled', automatic ? 'true' : 'false');
                input.classList.toggle('opacity-50', automatic);
            });

            document.querySelectorAll('.manual-estimate').forEach(element => {
                element.classList.toggle('hidden', automatic);
            });

            document.querySelectorAll('.auto-estimate').forEach(element => {
                element.classList.toggle('hidden', !automatic);
            });
        }

        document.addEventListener('codex:page-ready', initMMRAssistant);
        initMMRAssistant();
    </script>
@endpush
