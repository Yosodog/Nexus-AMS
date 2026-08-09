@extends('admin.settings.layout')

@section('settings-title', 'Security and retention settings')
@section('settings-subtitle', 'Manage backups, audit history, and automatic account disabling.')

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
