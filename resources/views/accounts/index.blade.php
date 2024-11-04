@extends('layouts.main')

@section("content")
    @include("accounts.components.account_overview")

    <div class="divider"></div>

    <div class="grid grid-cols-2 gap-6">
        <div>
            @include("accounts.components.create")
        </div>
        <div>
            @include("accounts.components.delete")
        </div>
    </div>

    <div class="divider"></div>

    @include("accounts.components.transfer")
@endsection
