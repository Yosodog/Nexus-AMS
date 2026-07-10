@extends('layouts.error')

@section('title', 'Service unavailable')
@section('code', '503')
@section('heading', 'Alliance operations are temporarily unavailable')
@section('message', 'The application is in maintenance or a required service is unavailable. Access will return when the operation is complete.')
@section('preserved', 'Do not repeat financial or approval actions elsewhere. Return after service is restored and confirm their status here.')

@section('actions')
    <a href="{{ url()->current() }}" class="btn btn-primary">Check again</a>
@endsection
