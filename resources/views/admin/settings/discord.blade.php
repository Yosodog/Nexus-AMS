@extends('admin.settings.layout')

@section('settings-title', 'Discord Settings')
@section('settings-subtitle', 'Manage account verification, workflow notifications, role tiers, and departure alerts.')

@section('settings-content')
    @include('admin.settings.partials.discord')
@endsection
