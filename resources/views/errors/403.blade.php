@php
    $currentUser = auth()->user();
    $accountRouteParameter = request()->route('accounts');
    $adminAccountUrl = null;

    if ($currentUser?->is_admin && request()->routeIs('accounts.view') && $accountRouteParameter) {
        $adminAccountUrl = route('admin.accounts.view', ['accounts' => $accountRouteParameter]);
    }
@endphp

@extends('layouts.error')

@section('title', 'Access restricted')
@section('code', '403')
@section('heading', 'You do not have access to this area')
@section('message', $exception->getMessage() ?: 'Your account is signed in, but it does not have the permission required for this page or action.')
@section('preserved', 'No changes were made. You can return to an area available to your role.')

@section('actions')
    @if ($adminAccountUrl)
        <a href="{{ $adminAccountUrl }}" class="btn btn-primary">Open admin account</a>
    @elseif (auth()->check())
        <a href="{{ route('user.dashboard') }}" class="btn btn-primary">Open member overview</a>
    @else
        <a href="{{ route('login') }}" class="btn btn-primary">Sign in</a>
    @endif
@endsection

@section('support', 'If this access is expected for your role, ask an alliance administrator to review your permissions.')
