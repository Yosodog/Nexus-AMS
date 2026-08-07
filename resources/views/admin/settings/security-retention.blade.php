@extends('admin.settings.layout')

@section('settings-title', 'Security & Retention Settings')
@section('settings-subtitle', 'Manage backup availability, audit retention, and account inactivity controls within their existing permissions.')

@section('settings-content')
    <div class="space-y-8">
        @can('view-diagnostic-info')
            @include('admin.settings.partials.security-retention')
        @endcan

        @can('edit-users')
            @include('admin.settings.partials.people')
        @endcan
    </div>
@endsection
