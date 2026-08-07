@extends('admin.settings.layout')

@section('settings-title', 'Finance Policy Settings')
@section('settings-subtitle', 'Manage independent availability controls for withdrawals, loan payments, and grant approvals.')

@section('settings-content')
    @include('admin.settings.partials.finance')
@endsection
