@extends('layouts.admin')

@section('title', 'Create Audit Rule')

@section('content')
    <div class="mb-6">
        <div class="w-full">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">New Audit Rule</h3>
                    <p class="text-base-content/50 mb-0">Define a NEL expression against nations or cities.</p>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.audits.rules.store') }}">
        @csrf
        <div class="card shadow-sm border-0">
            <div class="card-body">
                @include('admin.audits.rules._form')
            </div>
            <div class="card-footer flex justify-content-between">
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="o-check me-1"></i>
                    Save rule
                </button>
            </div>
        </div>
    </form>
@endsection
