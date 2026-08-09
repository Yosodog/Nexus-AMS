@extends('admin.settings.layout')

@section('settings-title', 'System health')
@section('settings-subtitle', 'Check whether scheduled tasks, data imports, and connected services are running normally.')

@section('settings-content')
    @include('components.admin.system-health', ['health' => $systemHealth])
@endsection
