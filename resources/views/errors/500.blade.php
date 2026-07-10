@extends('layouts.error')

@section('title', 'Unexpected error')
@section('code', '500')
@section('heading', 'The application could not finish that request')
@section('message', 'An unexpected server error interrupted the operation. No technical details are shown here for security.')
@section('preserved', 'The final state is uncertain. For payments, transfers, grants, or approvals, check the relevant record before retrying.')

@section('actions')
    <a href="{{ url()->previous() }}" class="btn btn-primary">Return to the previous page</a>
@endsection

@section('support', 'If the problem continues, report the time and the action you attempted to an alliance administrator.')
