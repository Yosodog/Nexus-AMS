@extends('layouts.main')

@section("content")
    @include("accounts.components.account_overview")

    <div class="divider"></div>

    <div class="grid grid-cols-2 gap-6">
        <div>
            @include("accounts.components.create")
        </div>
        <div>
            @include("accounts.components.delete")
        </div>
    </div>

    <div class="divider"></div>

    @include("accounts.components.transfer")
    @include("accounts.components.deposit_js")

    <div class="divider"></div>

    @include("accounts.components.direct_deposit")
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
