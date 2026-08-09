@extends('admin.settings.layout')

@section('settings-title', 'Data synchronization')
@section('settings-subtitle', 'Review recent updates or start a manual Politics & War sync when needed.')

@section('settings-content')
    @include('admin.settings.partials.data-sync')
@endsection
