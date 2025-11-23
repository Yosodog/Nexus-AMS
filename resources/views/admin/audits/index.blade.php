@extends('layouts.admin')

@section('title', 'Audits')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">Audit Overview</h3>
                    <p class="text-muted mb-0">Track active rules and live violations across your membership.</p>
                </div>
                <div class="col-auto">
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('admin.audits.run') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="bi bi-play-fill me-1"></i>
                                Run audits now
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.audits.notify') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-success">
                                <i class="bi bi-send-fill me-1"></i>
                                Notify members
                            </button>
                        </form>
                        <a href="{{ route('admin.audits.rules.create') }}" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>
                            New Rule
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold">Enabled rules</span>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="display-6 fw-bold">{{ $summary['enabled_rules'] }}</div>
                        <span class="badge bg-primary-subtle text-primary-emphasis">/{{ $summary['total_rules'] }} total</span>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Rules currently participating in scheduled audits.</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold">Open violations</span>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="display-6 fw-bold">{{ $summary['violations_total'] }}</div>
                        <i class="bi bi-exclamation-octagon text-danger fs-3"></i>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Live rows in <code>audit_results</code>.</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold">By priority</span>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <span class="badge bg-danger-subtle text-danger-emphasis">
                            High {{ $summary['violations_by_priority']['high'] ?? 0 }}
                        </span>
                        <span class="badge bg-warning-subtle text-warning-emphasis">
                            Medium {{ $summary['violations_by_priority']['medium'] ?? 0 }}
                        </span>
                        <span class="badge bg-info-subtle text-info-emphasis">
                            Low {{ $summary['violations_by_priority']['low'] ?? 0 }}
                        </span>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">
                            Info {{ $summary['violations_by_priority']['info'] ?? 0 }}
                        </span>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Current distribution across rule severity.</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold">By target</span>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <span class="badge bg-primary-subtle text-primary-emphasis">
                            Nation {{ $summary['violations_by_target']['nation'] ?? 0 }}
                        </span>
                        <span class="badge bg-info-subtle text-info-emphasis">
                            City {{ $summary['violations_by_target']['city'] ?? 0 }}
                        </span>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Where violations are currently anchored.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Rule coverage</h5>
                <span class="text-muted small">Live snapshot of all rules with their current violation counts.</span>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-outline-secondary btn-sm">
                    Manage rules
                </a>
                <a href="{{ route('admin.nel.docs') }}" class="btn btn-outline-primary btn-sm">
                    NEL syntax
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th scope="col">Rule</th>
                    <th scope="col">Target</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-center">Violations</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $rule->name }}</div>
                            <div class="text-muted small text-truncate" style="max-width: 420px;">
                                {{ $rule->description ?? 'No description provided.' }}
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-{{ $rule->target_type->value === 'nation' ? 'primary' : 'info' }}">
                                {{ ucfirst($rule->target_type->value) }}
                            </span>
                        </td>
                        <td>
                            @php
                                $priorityClass = [
                                    'high' => 'danger',
                                    'medium' => 'warning',
                                    'low' => 'info',
                                    'info' => 'secondary',
                                ][$rule->priority->value] ?? 'secondary';
                            @endphp
                            <span class="badge bg-{{ $priorityClass }}">
                                {{ ucfirst($rule->priority->value) }}
                            </span>
                        </td>
                        <td>
                            @if($rule->enabled)
                                <span class="badge bg-success-subtle text-success-emphasis">Enabled</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Disabled</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">
                                {{ $rule->results_count }}
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group">
                                <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-activity me-1"></i>Violations
                                </a>
                                <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            No audit rules defined yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
