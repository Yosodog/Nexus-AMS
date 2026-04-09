@extends('layouts.admin')

@section('title', 'Create Audit Rule')

@section('content')
    <x-header title="New Audit Rule" separator>
        <x-slot:subtitle>Define a NEL expression against nations or cities.</x-slot:subtitle>
    </x-header>

    <form method="POST" action="{{ route('admin.audits.rules.store') }}">
        @csrf
        <x-card title="Rule details" subtitle="Expressions are validated before save.">
            @include('admin.audits.rules._form')

            <x-slot:menu>
                <div class="flex gap-2">
                    <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-ghost btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="o-check" class="size-4" />
                        Save rule
                    </button>
                </div>
            </x-slot:menu>
        </x-card>
    </form>
@endsection
