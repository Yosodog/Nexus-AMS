@php
    $currentUser = auth()->user();
    $accountRouteParameter = request()->route('accounts');

    $adminAccountUrl = null;

    if (
        $currentUser?->is_admin
        && request()->routeIs('accounts.view')
        && $accountRouteParameter
    ) {
        $adminAccountUrl = route('admin.accounts.view', ['accounts' => $accountRouteParameter]);
    }
@endphp

@extends('errors::minimal')

@section('title', __('Forbidden'))
@section('code', '403')

@section('message')
    <span>{{ $exception->getMessage() ?: __('Forbidden') }}</span>

    @if ($adminAccountUrl)
        <br>
        <a href="{{ $adminAccountUrl }}" class="underline">Go to admin account management</a>
    @endif
@endsection
