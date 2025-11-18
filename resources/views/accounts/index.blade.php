@extends('layouts.main')

@section("content")
    <div class="mx-auto space-y-8">
        @php
            $totalCash = $accounts->sum('money');
        @endphp

        <div class="rounded-2xl bg-base-100 border border-base-300 p-6 shadow-md">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Banking workspace</p>
                    <h1 class="text-2xl font-bold">Manage your accounts</h1>
                    <p class="text-sm text-base-content/70">Transfer, request deposits, and keep balances tidy.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <div class="rounded-xl bg-primary/10 border border-primary/30 px-4 py-3 text-primary">
                        <p class="text-xs uppercase">Total cash</p>
                        <p class="text-xl font-bold">${{ number_format($totalCash, 2) }}</p>
                    </div>
                    <div class="rounded-xl bg-base-200 border border-base-300 px-4 py-3">
                        <p class="text-xs uppercase text-base-content/70">Accounts</p>
                        <p class="text-xl font-bold">{{ $accounts->count() }}</p>
                    </div>
                </div>
            </div>
        </div>

        @include("accounts.components.account_overview")

        <div class="space-y-6">
            @include("accounts.components.transfer")
            @include("accounts.components.direct_deposit")
            <div class="grid gap-6 md:grid-cols-2">
                @include("accounts.components.create")
                @include("accounts.components.delete")
            </div>
        </div>

        @include("accounts.components.deposit_js")
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const afterTax = {{ $mmrAfterTaxIncome }};
            const badge = document.getElementById('totalPct');

            function updateMMREstimates() {
                let total = 0;

                document.querySelectorAll('.resource-input').forEach(input => {
                    const raw = parseFloat(input.value || 0);
                    const percent = raw / 100;

                    let ppu = parseFloat(input.dataset.ppu);
                    if (isNaN(ppu)) ppu = 0;

                    total += raw;

                    const estimate = (percent > 0 && ppu > 0)
                        ? ((afterTax * percent) / ppu)
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

                const isOver = total > 100;
                const isUnder = total < 0;

                badge.classList.toggle('bg-error', isOver || isUnder);
                badge.classList.toggle('bg-success', !isOver && !isUnder);
                badge.classList.toggle('animate-pulse', isOver);
            }

            document.querySelectorAll('.resource-input').forEach(input => {
                input.addEventListener('input', updateMMREstimates);
            });

            updateMMREstimates();

            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new bootstrap.Tooltip(el);
            });
        });
    </script>
@endpush
