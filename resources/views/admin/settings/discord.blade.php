@extends('admin.settings.layout')

@section('settings-title', 'Discord settings')
@section('settings-subtitle', 'Manage account verification, private notifications, role tiers, and departure alerts.')

@section('settings-content')
    @include('admin.settings.partials.discord')
@endsection
