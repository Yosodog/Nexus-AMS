@extends('layouts.admin')

@section('title', 'Create Audit Rule')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col">
                    <h3 class="mb-1">New Audit Rule</h3>
                    <p class="text-muted mb-0">Define a NEL expression against nations or cities.</p>
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
            <div class="card-footer d-flex justify-content-between">
                <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>
                    Save rule
                </button>
            </div>
        </div>
    </form>
@endsection
