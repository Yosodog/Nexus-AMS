@extends('layouts.admin')

@section('title', 'Audit Rules')

@section('content')
    <x-header title="Audit Rules" separator use-h1>
        <x-slot:subtitle>Build, test, and monitor plain-language checks for member nations and cities.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.audits.index') }}" class="btn btn-outline">
                    <x-icon name="o-arrow-left" class="size-5" aria-hidden="true" />
                    Overview
                </a>
                @can('manage-audits')
                    <a href="{{ route('admin.audits.rules.create') }}" class="btn btn-primary">
                        <x-icon name="o-plus-circle" class="size-5" aria-hidden="true" />
                        New rule
                    </a>
                @endcan
            </div>
        </x-slot:actions>
    </x-header>

    @if($rules->contains(fn ($rule) => $rule->last_evaluation_status?->value === 'migration_failed'))
        <div class="alert alert-warning items-start" role="alert">
            <x-icon name="o-exclamation-triangle" class="mt-1 size-5 shrink-0" aria-hidden="true" />
            <div>
                <div class="font-semibold">Some imported rules need to be rebuilt</div>
                <p class="text-sm">They are disabled and cannot open findings until an admin adds guided conditions and confirms their impact.</p>
            </div>
        </div>
    @endif

    <section class="nexus-panel" aria-labelledby="rule-library-heading">
        <div class="nexus-panel__header">
            <div>
                <h2 id="rule-library-heading" class="nexus-section-title">Rule library</h2>
                <p class="mt-1 text-sm text-base-content/60">{{ number_format($rules->count()) }} matching {{ \Illuminate\Support\Str::plural('rule', $rules->count()) }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.audits.rules.index') }}" class="grid gap-3 border-b border-base-300 p-4 md:grid-cols-2 lg:grid-cols-6 lg:p-6">
            <label class="fieldset gap-1 lg:col-span-2">
                <span class="fieldset-legend">Search</span>
                <input class="input w-full" type="search" name="search" value="{{ request('search') }}" placeholder="Rule name or explanation">
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Target</span>
                <select class="select w-full" name="target">
                    <option value="">All targets</option>
                    @foreach($targetTypes as $targetType)
                        <option value="{{ $targetType->value }}" @selected(request('target') === $targetType->value)>{{ ucfirst($targetType->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Priority</span>
                <select class="select w-full" name="priority">
                    <option value="">All priorities</option>
                    @foreach($priorities as $priority)
                        <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>{{ ucfirst($priority->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Enabled</span>
                <select class="select w-full" name="enabled">
                    <option value="">Any state</option>
                    <option value="1" @selected(request('enabled') === '1')>Enabled</option>
                    <option value="0" @selected(request('enabled') === '0')>Disabled</option>
                </select>
            </label>
            <label class="fieldset gap-1">
                <span class="fieldset-legend">Health</span>
                <select class="select w-full" name="status">
                    <option value="">Any status</option>
                    @foreach($evaluationStatuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end gap-2 md:col-span-2 lg:col-span-6 lg:justify-end">
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-ghost">Clear</a>
                <button class="btn btn-outline btn-primary" type="submit">
                    <x-icon name="o-funnel" class="size-5" aria-hidden="true" />
                    Apply filters
                </button>
            </div>
        </form>

        <div class="hidden overflow-x-auto md:block">
            <table class="table" data-sortable="true">
                <thead>
                <tr>
                    <th scope="col">Rule</th>
                    <th scope="col">Target & priority</th>
                    <th scope="col">Health</th>
                    <th scope="col" class="text-center">Findings</th>
                    <th scope="col" class="text-right" data-sortable="false">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    @php
                        $priorityBadge = match ($rule->priority->value) {
                            'high' => 'badge-error',
                            'medium' => 'badge-warning',
                            'low' => 'badge-info',
                            default => 'badge-ghost',
                        };
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
                            <p class="mt-1 line-clamp-2 text-sm text-base-content/60">{{ $rule->plain_language_summary }}</p>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <span class="badge badge-outline">{{ ucfirst($rule->target_type->value) }}</span>
                                <span class="badge {{ $priorityBadge }}">{{ ucfirst($rule->priority->value) }}</span>
                                <span class="badge {{ $rule->enabled ? 'badge-success badge-soft' : 'badge-ghost' }}">{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $healthBadge }} badge-soft">{{ $rule->last_evaluation_status?->label() ?? 'Never run' }}</span>
                            <div class="mt-2 text-sm text-base-content/60">
                                {{ $rule->last_evaluated_at?->diffForHumans() ?? 'No evaluation yet' }}
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="text-lg font-semibold tabular-nums">{{ number_format($rule->results_count) }}</span>
                        </td>
                        <td class="text-right">
                            <div class="inline-flex items-center gap-2">
                                <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-sm btn-outline" aria-label="View findings for {{ $rule->name }}">
                                    <x-icon name="o-flag" class="size-4" aria-hidden="true" />
                                </a>
                                @can('manage-audits')
                                    <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-sm btn-outline btn-primary" aria-label="Edit {{ $rule->name }}">
                                        <x-icon name="o-pencil" class="size-4" aria-hidden="true" />
                                    </a>
                                    @if($rule->enabled)
                                        <form action="{{ route('admin.audits.rules.destroy', $rule) }}" method="POST" data-confirm="Disable this rule and close its current findings?" data-confirm-title="Disable audit rule?" data-confirm-label="Disable rule" data-confirm-tone="error">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline btn-error" aria-label="Disable {{ $rule->name }}">
                                                <x-icon name="o-no-symbol" class="size-4" aria-hidden="true" />
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-base-content/60">No rules match these filters.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-base-300 md:hidden">
            @forelse($rules as $rule)
                <article class="grid gap-4 p-4">
                    <div>
                        <h3 class="text-xl font-semibold">{{ $rule->name }}</h3>
                        <p class="mt-2 text-sm leading-5 text-base-content/60">{{ $rule->plain_language_summary }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-outline">{{ ucfirst($rule->target_type->value) }}</span>
                        <span class="badge badge-outline">{{ ucfirst($rule->priority->value) }}</span>
                        <span class="badge {{ $rule->enabled ? 'badge-success badge-soft' : 'badge-ghost' }}">{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-base-content/60">Health</dt><dd class="mt-1 font-semibold">{{ $rule->last_evaluation_status?->label() ?? 'Never run' }}</dd></div>
                        <div><dt class="text-base-content/60">Open findings</dt><dd class="mt-1 font-semibold tabular-nums">{{ number_format($rule->results_count) }}</dd></div>
                    </dl>
                    <div class="grid grid-cols-2 gap-2">
                        <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-outline">Findings</a>
                        @can('manage-audits')
                            <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-primary">Edit rule</a>
                        @endcan
                    </div>
                </article>
            @empty
                <p class="p-6 text-center text-base-content/60">No rules match these filters.</p>
            @endforelse
        </div>
    </section>
@endsection
