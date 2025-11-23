@extends('layouts.admin')

@section('title', 'Audit Violations')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">Violations: {{ $rule->name }}</h3>
                    <p class="text-muted mb-0">Live offenders for this rule. Rows clear automatically when targets comply.</p>
                </div>
                <div class="col-auto d-flex gap-2">
                    <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i>Edit rule
                    </a>
                    <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-outline-secondary">
                        Back to rules
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <span class="badge bg-{{ $rule->target_type->value === 'nation' ? 'primary' : 'info' }}">
                    {{ ucfirst($rule->target_type->value) }} rule
                </span>
                @php
                    $priorityClass = [
                        'high' => 'danger',
                        'medium' => 'warning',
                        'low' => 'info',
                        'info' => 'secondary',
                    ][$rule->priority->value] ?? 'secondary';
                @endphp
                <span class="badge bg-{{ $priorityClass }}">
                    Priority: {{ ucfirst($rule->priority->value) }}
                </span>
                <span class="text-muted small">Expression: <code class="font-monospace">{{ $rule->expression }}</code></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th scope="col">Target</th>
                    <th scope="col">First detected</th>
                    <th scope="col">Last evaluated</th>
                </tr>
                </thead>
                <tbody>
                @forelse($violations as $violation)
                    <tr>
                        <td>
                            @if($rule->target_type->value === 'nation')
                                <div class="fw-semibold">
                                    <a href="https://politicsandwar.com/nation/id={{ $violation->nation?->id }}"
                                       target="_blank" rel="noopener"
                                       class="text-decoration-none">
                                        {{ $violation->nation?->leader_name ?? 'Unknown leader' }}
                                        <span class="text-muted">({{ $violation->nation?->nation_name ?? 'Unknown nation' }})</span>
                                    </a>
                                </div>
                                <div class="text-muted small">
                                    Score {{ number_format((float) $violation->nation?->score, 2) }} ·
                                    Cities {{ $violation->nation?->num_cities }}
                                </div>
                            @else
                                <div class="fw-semibold">
                                    <a href="https://politicsandwar.com/city/id={{ $violation->city?->id }}"
                                       target="_blank" rel="noopener"
                                       class="text-decoration-none">
                                        {{ $violation->city?->name ?? 'Unknown city' }}
                                    </a>
                                    <span class="badge bg-light text-dark border ms-2">
                                        {{ $violation->city?->powered ? 'Powered' : 'Unpowered' }}
                                    </span>
                                </div>
                                <div class="text-muted small">
                                    Infra {{ number_format((float) $violation->city?->infrastructure, 0) }}
                                    · Land {{ number_format((float) $violation->city?->land, 0) }}
                                    @if($violation->nation)
                                        · <a href="https://politicsandwar.com/nation/id={{ $violation->nation->id }}"
                                             target="_blank" rel="noopener"
                                             class="text-decoration-none">{{ $violation->nation->leader_name }} ({{ $violation->nation->nation_name }})</a>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $violation->first_detected_at->diffForHumans() }}</div>
                            <div class="text-muted small">{{ $violation->first_detected_at->toDayDateTimeString() }}</div>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $violation->last_evaluated_at->diffForHumans() }}</div>
                            <div class="text-muted small">{{ $violation->last_evaluated_at->toDayDateTimeString() }}</div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">No current violations for this rule.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
