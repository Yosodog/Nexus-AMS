@extends('admin.settings.layout')

@section('settings-title', 'Pending Request Recovery')
@section('settings-subtitle', 'Inspect and explicitly release stale workflow rows without approving their business action.')

@section('settings-content')
    @include('admin.settings.partials.recovery')
@endsection
