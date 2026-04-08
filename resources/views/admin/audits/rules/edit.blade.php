@extends('layouts.admin')

@section('title', 'Edit Audit Rule')

@section('content')
    <div class="mb-6">
        <div class="w-full">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">Edit Audit Rule</h3>
                    <p class="text-base-content/50 mb-0">Update the expression or priority and keep violations in sync.</p>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.audits.rules.update', $rule) }}">
        @csrf
        @method('PUT')
        <div class="card shadow-sm border-0">
            <div class="card-body">
                @include('admin.audits.rules._form')
            </div>
            <div class="card-footer flex justify-content-between">
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <div class="flex gap-2">
                    <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-outline-secondary">
                        <i class="o-bolt me-1"></i>Violations
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="o-check me-1"></i>
                        Update rule
                    </button>
                </div>
            </div>
        </div>
    </form>
@endsection
