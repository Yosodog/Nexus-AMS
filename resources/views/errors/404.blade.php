@extends('layouts.error')

@section('title', 'Page not found')
@section('code', '404')
@section('heading', 'This page is not in the current record')
@section('message', 'The link may be outdated, the item may have been removed, or the address may be incomplete.')
@section('preserved', 'Nothing was changed. Check the address or continue from the application navigation.')

@section('actions')
    @auth
        <a href="{{ route('user.dashboard') }}" class="btn btn-primary">Open member overview</a>
    @endauth
@endsection
