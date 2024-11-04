@extends('layouts.main')

@section("content")
    @if ($accounts->count() === 0)
        @include("accounts.components.create")
    @endif


@endsection
