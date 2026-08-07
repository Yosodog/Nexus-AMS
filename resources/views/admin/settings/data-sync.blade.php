@extends('admin.settings.layout')

@section('settings-title', 'Data Synchronization')
@section('settings-subtitle', 'Review rolling progress and run exceptional manual Politics & War synchronization.')

@section('settings-content')
    @include('admin.settings.partials.data-sync')
@endsection
