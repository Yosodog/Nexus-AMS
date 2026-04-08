@extends('layouts.admin')

@section('title', 'Audit Rules')

@section('content')
    <div class="mb-6">
        <div class="w-full">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">Audit Rules</h3>
                    <p class="text-base-content/50 mb-0">Create, update, and retire NEL-powered checks.</p>
                </div>
                <div class="col-auto">
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
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Rule library</h5>
                <span class="text-base-content/50 small">Expressions are parsed with NEL; invalid rules are blocked on save.</span>
            </div>
            <a href="{{ route('admin.nel.docs') }}" class="btn btn-outline-secondary btn-sm">
                <i class="o-document-text-code me-1"></i>NEL Docs
            </a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
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
                                <span class="badge bg-success-subtle text-success-emphasis">Yes</span>
                            @else
                                <span class="badge bg-secondary-subtle text-base-content/50-emphasis">No</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">{{ $rule->results_count }}</span>
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
    </div>
@endsection
