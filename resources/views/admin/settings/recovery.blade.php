@extends('admin.settings.layout')

@section('settings-title', 'Stuck request recovery')
@section('settings-subtitle', 'Close requests that are stuck in a pending state without approving them.')

@section('settings-content')
    @include('admin.settings.partials.recovery')
@endsection
