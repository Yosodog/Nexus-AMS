@extends('layouts.admin')

@section('title', 'Audits')

@section('content')
    <x-header title="Audit Overview" separator>
        <x-slot:subtitle>Track active rules, triage live violations, and manage member notifications from one place.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.audits.run') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <x-icon name="o-play" class="size-4" />
                        Run audits
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.audits.notify') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-success btn-sm">
                        <x-icon name="o-paper-airplane" class="size-4" />
                        Notify members
                    </button>
                </form>
                <a href="{{ route('admin.audits.rules.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="o-plus-circle" class="size-4" />
                    New rule
                </a>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat
            title="Enabled Rules"
            :value="number_format($summary['enabled_rules'])"
            icon="o-check-circle"
            color="text-primary"
            :description="number_format($summary['total_rules']) . ' total rules configured'"
            class="admin-stat-card admin-stat-card-primary"
        />
        <x-stat
            title="Open Violations"
            :value="number_format($summary['violations_total'])"
            icon="o-exclamation-triangle"
            color="text-error"
            description="Live rows currently requiring attention"
            class="admin-stat-card admin-stat-card-error"
        />
        <x-stat
            title="High Priority"
            :value="number_format($summary['violations_by_priority']['high'] ?? 0)"
            icon="o-bolt"
            color="text-warning"
            :description="'Medium ' . number_format($summary['violations_by_priority']['medium'] ?? 0) . ' · Low ' . number_format($summary['violations_by_priority']['low'] ?? 0)"
            class="admin-stat-card admin-stat-card-warning"
        />
        <x-stat
            title="Target Split"
            :value="number_format($summary['violations_by_target']['nation'] ?? 0) . ' nation'"
            icon="o-users"
            color="text-info"
            :description="number_format($summary['violations_by_target']['city'] ?? 0) . ' city violations'"
            class="admin-stat-card admin-stat-card-info"
        />
    </div>

    <x-card>
        <div class="card-header flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Rule coverage</h5>
                <span class="text-base-content/50 small">Live snapshot of all rules with their current violation counts.</span>
            </div>
            <div class="flex gap-2">
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
                    <th scope="col" class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    <tr>
                        <td>
                            <div class="font-semibold">{{ $rule->name }}</div>
                            <div class="text-base-content/50 small text-truncate" style="max-width: 420px;">
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
                                <span class="badge bg-secondary-subtle text-base-content/50-emphasis">Disabled</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">
                                {{ $rule->results_count }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="btn-group">
                                <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="o-bolt me-1"></i>Violations
                                </a>
                                <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="o-pencil me-1"></i>Edit
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-base-content/50 py-4">
                            No audit rules defined yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
