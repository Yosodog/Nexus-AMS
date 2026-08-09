@extends('admin.settings.layout')

@section('settings-title', 'Finance policy settings')
@section('settings-subtitle', 'Pause or resume automatic withdrawals, loan payments, and grant approvals.')

@section('settings-content')
    @include('admin.settings.partials.finance')
@endsection
