@extends('layouts.admin')

@section('title', 'Audit Rules')

@section('content')
    <x-header title="Audit Rules" separator>
        <x-slot:subtitle>Create, update, and retire NEL-powered checks.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex gap-2">
                <a href="{{ route('admin.audits.index') }}" class="btn btn-outline-secondary">
                    <i class="o-arrow-left me-1"></i>
                    Back to overview
                </a>
                <a href="{{ route('admin.audits.rules.create') }}" class="btn btn-primary">
                    <i class="o-plus-circle me-1"></i>
                    New Rule
                </a>
            </div>
        </x-slot:actions>
    </x-header>

    <x-card title="Rule library" subtitle="Expressions are parsed with NEL; invalid rules are blocked on save.">
        <x-slot:menu>
            <a href="{{ route('admin.nel.docs') }}" class="btn btn-outline-secondary btn-sm">
                <i class="o-document-text-code me-1"></i>NEL Docs
            </a>
        </x-slot:menu>
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th scope="col">Rule</th>
                    <th scope="col">Target</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Enabled</th>
                    <th scope="col">Violations</th>
                    <th scope="col" style="width: 180px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    <tr>
                        <td>
                            <div class="font-semibold">{{ $rule->name }}</div>
                            <div class="text-base-content/50 small">{{ $rule->description ?? 'No description' }}</div>
                            <code class="small d-block mt-1 text-wrap">{{ $rule->expression }}</code>
                        </td>
                        <td>
                            <span class="badge {{ $rule->target_type->value === 'nation' ? 'badge-primary' : 'badge-info' }}">
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
                                $priorityBadgeClass = match ($priorityClass) {
                                    'danger' => 'badge-error',
                                    'warning' => 'badge-warning',
                                    'info' => 'badge-info',
                                    default => 'badge-ghost',
                                };
                            @endphp
                            <span class="badge {{ $priorityBadgeClass }}">
                                {{ ucfirst($rule->priority->value) }}
                            </span>
                        </td>
                        <td>
                            @if($rule->enabled)
                                <span class="badge badge-success badge-soft">Yes</span>
                            @else
                                <span class="badge badge-ghost">No</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-outline">{{ $rule->results_count }}</span>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <a href="{{ route('admin.audits.rules.edit', $rule) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="o-pencil"></i>
                                </a>
                                <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="o-bolt"></i>
                                </a>
                                <form action="{{ route('admin.audits.rules.destroy', $rule) }}" method="POST" onsubmit="return confirm('Disable this rule and clear its violations?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="o-no-symbol"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-base-content/50 py-4">No audit rules have been configured yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
