@extends('layouts.admin')

@section('title', 'Create Audit Rule')

@section('content')
    <x-header title="New Audit Rule" separator use-h1>
        <x-slot:subtitle>Build a plain-language check and review who it will affect before activation.</x-slot:subtitle>
    </x-header>

    <form
        method="POST"
        action="{{ route('admin.audits.rules.store') }}"
        data-audit-rule-builder
        data-audit-preview-url="{{ route('admin.audits.rules.preview') }}"
        data-existing-enabled="false"
        data-existing-fingerprint=""
    >
        @csrf
        @include('admin.audits.rules._form')
    </form>
@endsection
