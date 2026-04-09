@extends('layouts.admin')

@section('title', 'Edit Audit Rule')

@section('content')
    <x-header title="Edit Audit Rule" separator>
        <x-slot:subtitle>Update the expression or priority and keep violations in sync.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-primary btn-outline btn-sm">
                <x-icon name="o-bolt" class="size-4" />
                Violations
            </a>
        </x-slot:actions>
    </x-header>

    <form method="POST" action="{{ route('admin.audits.rules.update', $rule) }}">
        @csrf
        @method('PUT')

        <x-card title="Rule details" subtitle="Adjust the expression, target, and notification copy.">
            @include('admin.audits.rules._form')

            <x-slot:menu>
                <div class="flex gap-2">
                    <a href="{{ route('admin.audits.rules.index') }}" class="btn btn-ghost btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="o-check" class="size-4" />
                        Update rule
                    </button>
                </div>
            </x-slot:menu>
        </x-card>
    </form>
@endsection
