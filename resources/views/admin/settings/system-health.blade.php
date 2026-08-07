@extends('admin.settings.layout')

@section('settings-title', 'System Health')
@section('settings-subtitle', 'Review scheduler, API, import, and data-freshness contracts without loading mutation forms.')

@section('settings-content')
    @include('components.admin.system-health', ['health' => $systemHealth])
@endsection
