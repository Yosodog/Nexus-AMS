@php
    $guide = config('war.nation_match.factor_explanations', []);
    $relative = config('war.nation_match.relative_power', []);
@endphp

<x-card title="Match Score Guide" subtitle="Quick reference for planners reviewing assignments." class="h-full">
    <dl class="mb-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[minmax(0,10rem)_1fr]">
            @foreach($guide as $key => $description)
            <dt class="text-xs font-semibold uppercase tracking-wide text-base-content/55">{{ str_replace('_', ' ', $key) }}</dt>
            <dd>{{ $description }}</dd>
            @endforeach
    </dl>

    <div class="border-t border-base-300 pt-4 text-sm">
        <div class="mb-2 font-semibold">Relative Power Tuning</div>
        <ul class="space-y-1 text-base-content/80">
            <li>Auto ratio floor: {{ number_format((float) ($relative['auto_ratio_floor'] ?? 0.48), 2) }}</li>
            <li>Manual ratio floor: {{ number_format((float) ($relative['manual_ratio_floor'] ?? 0.38), 2) }}</li>
            <li>Impact ceiling: {{ number_format((float) ($relative['ratio_ceiling'] ?? 0.95), 2) }}</li>
            <li>Auto minimum cap: {{ number_format((float) ($relative['auto_min_cap'] ?? 24), 0) }}</li>
            <li>Manual minimum cap: {{ number_format((float) ($relative['manual_min_cap'] ?? 40), 0) }}</li>
        </ul>
        <p class="mt-3 text-base-content/60">Relative power blends city count, nation score, and estimated military strength; low parity caps the total match regardless of other bonuses.</p>
    </div>
</x-card>
