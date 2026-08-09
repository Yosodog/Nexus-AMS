@extends('admin.settings.layout')

@section('settings-title', 'Public site settings')
@section('settings-subtitle', 'Manage homepage copy, search visibility, sharing previews, and the browser icon.')

@section('settings-content')
    @include('admin.settings.partials.public-site')
@endsection
