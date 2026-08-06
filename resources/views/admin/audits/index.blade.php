@extends('layouts.admin')

@section('title', 'Audits')

@section('content')
    <x-header title="Audit Overview" separator use-h1>
        <x-slot:subtitle>Monitor rule health, review urgent findings, and manage the guided rule library.</x-slot:subtitle>
        @can('manage-audits')
            <x-slot:actions>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.audits.run') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-primary">
                            <x-icon name="o-play" class="size-5" aria-hidden="true" />
                            Run audits
                        </button>
                    </form>
                    <a href="{{ route('admin.audits.rules.create') }}" class="btn btn-primary">
                        <x-icon name="o-plus-circle" class="size-5" aria-hidden="true" />
                        New rule
                    </a>
                </div>
            </x-slot:actions>
        @endcan
    </x-header>

    @if($rules->contains(fn ($rule) => $rule->last_evaluation_status?->value === 'migration_failed'))
        <div class="alert alert-warning items-start" role="alert">
            <x-icon name="o-exclamation-triangle" class="mt-1 size-5 shrink-0" aria-hidden="true" />
            <div>
                <div class="font-semibold">Imported rules need attention</div>
                <p class="text-sm">One or more rules could not be converted safely and remain disabled until they are rebuilt.</p>
                <a href="{{ route('admin.audits.rules.index', ['status' => 'migration_failed']) }}" class="link link-hover mt-2 inline-block font-semibold">Review rules needing rebuild</a>
            </div>
        </div>
    @endif

    <section class="nexus-metrics" aria-label="Audit operations summary">
        <div class="nexus-metric bg-error/5">
            <span class="nexus-stat-label">Open findings</span>
            <strong class="nexus-stat-value text-error">{{ number_format($summary['violations_total']) }}</strong>
            <span class="nexus-stat-description">{{ number_format($summary['overdue_findings']) }} overdue</span>
        </div>
        <div class="nexus-metric">
            <span class="nexus-stat-label">High priority</span>
            <strong class="nexus-stat-value">{{ number_format($summary['violations_by_priority']['high'] ?? 0) }}</strong>
            <span class="nexus-stat-description">Need prompt attention</span>
        </div>
        <div class="nexus-metric">
            <span class="nexus-stat-label">Enabled rules</span>
            <strong class="nexus-stat-value">{{ number_format($summary['enabled_rules']) }}</strong>
            <span class="nexus-stat-description">of {{ number_format($summary['total_rules']) }} configured</span>
        </div>
        <div class="nexus-metric">
            <span class="nexus-stat-label">Rule health</span>
            <strong class="nexus-stat-value">{{ number_format($summary['unhealthy_rules']) }}</strong>
            <span class="nexus-stat-description">warning or failed</span>
        </div>
    </section>

    @if($attentionFindings->isNotEmpty())
        <section class="nexus-panel" aria-labelledby="attention-findings-heading">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="attention-findings-heading" class="nexus-section-title">Needs attention</h2>
                    <p class="mt-1 text-sm nexus-text-muted">High-priority and overdue findings, ordered by urgency.</p>
                </div>
            </div>
            <div class="divide-y divide-base-300">
                @foreach($attentionFindings as $finding)
                    <article class="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between md:p-6">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-xl font-semibold">{{ $finding->rule?->name ?? 'Audit finding' }}</h3>
                                @if($finding->due_at?->isPast())
                                    <span class="badge badge-error">Overdue</span>
                                @endif
                                <span class="badge badge-outline">{{ ucfirst($finding->rule?->priority?->value ?? 'info') }}</span>
                            </div>
                            <p class="mt-1 text-sm nexus-text-muted">
                                {{ $finding->city?->name ?? 'Nation-wide' }} · {{ $finding->nation?->nation_name ?? 'Unknown nation' }}
                                @if($finding->due_at)
                                    · Due {{ $finding->due_at->toFormattedDateString() }}
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('admin.audits.rules.violations', $finding->audit_rule_id) }}" class="btn btn-outline btn-sm md:shrink-0">Review finding</a>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="nexus-panel" aria-labelledby="rule-health-heading">
        <div class="nexus-panel__header">
            <div>
                <h2 id="rule-health-heading" class="nexus-section-title">Rule health</h2>
                <p class="mt-1 text-sm nexus-text-muted">Current evaluation status and open-finding count for every rule.</p>
            </div>
            <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-outline btn-sm">Rules</a>
        </div>

        <div class="hidden overflow-x-auto md:block">
            <table class="table" data-sortable="true">
                <thead>
                <tr>
                    <th scope="col">Rule</th>
                    <th scope="col">Target</th>
                    <th scope="col">Evaluation health</th>
                    <th scope="col">Last run</th>
                    <th scope="col" class="text-center">Findings</th>
                    <th scope="col" class="text-right" data-sortable="false">Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    @php
                        $healthBadge = match ($rule->last_evaluation_status?->value) {
                            'success' => 'badge-success',
                            'warning' => 'badge-warning',
                            'failed', 'migration_failed' => 'badge-error',
                            'pending' => 'badge-info',
                            default => 'badge-ghost',
                        };
                    @endphp
                    <tr>
                        <td class="max-w-prose whitespace-normal">
                            <div class="font-semibold">{{ $rule->name }}</div>
                            <p class="mt-1 line-clamp-2 text-sm nexus-text-muted">{{ $rule->plain_language_summary }}</p>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <span class="badge badge-outline">{{ ucfirst($rule->target_type->value) }}</span>
                                <span class="badge {{ $rule->enabled ? 'badge-success badge-soft' : 'badge-ghost' }}">{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $healthBadge }} badge-soft">{{ $rule->last_evaluation_status?->label() ?? 'Never run' }}</span>
                            @if($rule->last_evaluation_error)
                                <p class="mt-2 max-w-xs whitespace-normal text-sm nexus-text-muted">{{ $rule->last_evaluation_error }}</p>
                            @endif
                        </td>
                        <td data-order="{{ $rule->last_evaluated_at?->timestamp ?? 0 }}">
                            {{ $rule->last_evaluated_at?->diffForHumans() ?? 'Not yet' }}
                        </td>
                        <td class="text-center text-lg font-semibold tabular-nums">{{ number_format($rule->results_count) }}</td>
                        <td class="text-right">
                            <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-sm btn-outline">Findings</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center nexus-text-muted">No audit rules have been configured.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-base-300 md:hidden">
            @forelse($rules as $rule)
                <article class="grid gap-3 p-4">
                    <div>
                        <h3 class="text-xl font-semibold">{{ $rule->name }}</h3>
                        <p class="mt-1 text-sm nexus-text-muted">{{ $rule->plain_language_summary }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-outline">{{ ucfirst($rule->target_type->value) }}</span>
                        <span class="badge {{ $rule->enabled ? 'badge-success badge-soft' : 'badge-ghost' }}">{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</span>
                        <span class="badge badge-outline">{{ $rule->last_evaluation_status?->label() ?? 'Never run' }}</span>
                    </div>
                    <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-outline">{{ number_format($rule->results_count) }} findings</a>
                </article>
            @empty
                <p class="p-6 text-center nexus-text-muted">No audit rules have been configured.</p>
            @endforelse
        </div>
    </section>
@endsection
