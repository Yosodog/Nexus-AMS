@php
    $guide = config('war.nation_match.factor_explanations', []);
    $relative = config('war.nation_match.relative_power', []);
@endphp

<div class="card shadow-sm h-100">
    <div class="card-header">
        <h5 class="card-title mb-0">Match Score Guide</h5>
        <small class="text-muted">Quick reference for planners reviewing assignments.</small>
    </div>
    <div class="card-body">
        <dl class="row small mb-3">
            @foreach($guide as $key => $description)
                <dt class="col-sm-4 text-uppercase">{{ str_replace('_', ' ', $key) }}</dt>
                <dd class="col-sm-8">{{ $description }}</dd>
            @endforeach
        </dl>
        <hr>
        <div class="small">
            <strong>Relative Power Tuning</strong>
            <ul class="list-unstyled mb-2">
                <li>Auto ratio floor: {{ number_format((float) ($relative['auto_ratio_floor'] ?? 0.48), 2) }}</li>
                <li>Manual ratio floor: {{ number_format((float) ($relative['manual_ratio_floor'] ?? 0.38), 2) }}</li>
                <li>Impact ceiling: {{ number_format((float) ($relative['ratio_ceiling'] ?? 0.95), 2) }}</li>
                <li>Auto minimum cap: {{ number_format((float) ($relative['auto_min_cap'] ?? 24), 0) }}</li>
                <li>Manual minimum cap: {{ number_format((float) ($relative['manual_min_cap'] ?? 40), 0) }}</li>
            </ul>
            <p class="text-muted mb-0">Relative power blends city count, nation score, and estimated military strength; low parity caps the total match regardless of other bonuses.</p>
        </div>
    </div>
</div>
