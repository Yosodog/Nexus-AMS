@extends('layouts.error')

@section('title', 'Too many requests')
@section('code', '429')
@section('heading', 'Please pause before trying again')
@section('message', 'This account or connection reached a temporary request limit. The limit protects the application and its external integrations.')
@section('preserved', 'The most recent request may not have been processed. Wait briefly, then confirm the current state before retrying.')

@section('actions')
    <a href="{{ url()->previous() }}" class="btn btn-primary">Return to the previous page</a>
@endsection
