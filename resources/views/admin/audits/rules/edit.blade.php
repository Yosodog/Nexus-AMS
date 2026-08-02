@extends('layouts.admin')

@section('title', 'Edit Audit Rule')

@section('content')
    <x-header :title="'Edit ' . $rule->name" separator use-h1>
        <x-slot:subtitle>Update the rule in plain language. Logic changes create a fresh revision and replace current findings.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.audits.rules.violations', $rule) }}" class="btn btn-outline">
                <x-icon name="o-flag" class="size-5" aria-hidden="true" />
                View findings
            </a>
        </x-slot:actions>
    </x-header>

    <form
        method="POST"
        action="{{ route('admin.audits.rules.update', $rule) }}"
        data-audit-rule-builder
        data-audit-preview-url="{{ route('admin.audits.rules.preview') }}"
        data-existing-enabled="{{ $rule->enabled ? 'true' : 'false' }}"
        data-existing-fingerprint="{{ $initialFingerprint }}"
        data-rule-revision="{{ $rule->revision }}"
    >
        @csrf
        @method('PUT')
        @include('admin.audits.rules._form')
    </form>
@endsection
