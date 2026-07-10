@extends('layouts.error')

@section('title', 'Session expired')
@section('code', '419')
@section('heading', 'Your secure session expired')
@section('message', 'The page was open longer than the current security token allows, so the request was not accepted.')
@section('preserved', 'The submitted action was not processed. Copy any unsaved text before refreshing if it is still visible in another tab.')

@section('actions')
    <a href="{{ url()->current() }}" class="btn btn-primary">Refresh and try again</a>
@endsection

@section('support', 'For financial or approval actions, confirm the current status before submitting again to avoid a duplicate request.')
