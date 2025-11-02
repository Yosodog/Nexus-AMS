@php
    $factors = $meta['factors'] ?? [];
    $weights = $meta['weights'] ?? [];
@endphp

@if(empty($factors))
    <p class="small text-muted mb-0">No factor breakdown captured for this match.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-2">
            <thead class="table-light">
            <tr>
                <th>Factor</th>
                <th>Value</th>
                <th>Weight</th>
                <th>Impact</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            @foreach($factors as $key => $data)
                @php
                    $value = is_array($data) ? ($data['value'] ?? null) : $data;
                    $weight = is_array($data) ? ($data['weight'] ?? ($weights[$key] ?? 0)) : ($weights[$key] ?? 0);
                    $impact = is_array($data) ? ($data['impact'] ?? ($weight * (float) ($value ?? 0) * 100)) : ($weight * (float) ($value ?? 0) * 100);
                    $reason = is_array($data) ? ($data['reason'] ?? null) : null;
                    $extras = [];
                    if (is_array($data)) {
                        $extras = collect($data)->except(['value', 'weight', 'impact', 'reason'])->toArray();
                    }
                @endphp
                <tr>
                    <td class="text-uppercase small fw-semibold">{{ str_replace('_', ' ', $key) }}</td>
                    <td>{{ $value !== null ? number_format((float) $value, 4) : '—' }}</td>
                    <td>{{ number_format((float) $weight, 2) }}</td>
                    <td class="{{ ($impact ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format((float) $impact, 2) }}</td>
                    <td>
                        @if($reason)
                            <div>{{ $reason }}</div>
                        @endif
                        @if(!empty($extras))
                            <div class="small text-muted">
                                @foreach($extras as $extraKey => $extraValue)
                                    <span class="me-2">
                                        {{ str_replace('_', ' ', $extraKey) }}:
                                        @if(is_array($extraValue))
                                            {{ json_encode($extraValue, JSON_UNESCAPED_SLASHES) }}
                                        @elseif(is_float($extraValue))
                                            {{ number_format($extraValue, 2) }}
                                        @else
                                            {{ $extraValue }}
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <dl class="row small mb-2">
        <dt class="col-sm-4">Mode</dt>
        <dd class="col-sm-8 text-uppercase">{{ $meta['mode'] ?? 'auto' }}</dd>
        <dt class="col-sm-4">Raw score</dt>
        <dd class="col-sm-8">{{ number_format((float) ($meta['raw_score'] ?? 0), 2) }}</dd>
        <dt class="col-sm-4">After cap</dt>
        <dd class="col-sm-8">{{ number_format((float) ($meta['bounded'] ?? 0), 2) }}</dd>
        @if(!empty($meta['caps']['relative_power'] ?? null))
            <dt class="col-sm-4">Relative cap</dt>
            <dd class="col-sm-8">{{ number_format((float) $meta['caps']['relative_power'], 2) }}</dd>
        @endif
    </dl>

    @if(!empty($meta['relative_power_details'] ?? []))
        <div class="small text-muted">
            Relative power details:
            {{ json_encode($meta['relative_power_details'], JSON_UNESCAPED_SLASHES) }}
        </div>
    @endif
@endif
