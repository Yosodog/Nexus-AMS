@extends('admin.settings.layout')

@section('settings-title', 'Public Site Settings')
@section('settings-subtitle', 'Manage homepage copy, search and sharing metadata, and browser branding.')

@section('settings-content')
    @include('admin.settings.partials.public-site')
@endsection
