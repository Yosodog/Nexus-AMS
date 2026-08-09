@extends('layouts.admin')

@section('content')
    @php
        $settingsTitle = trim($__env->yieldContent('settings-title')) ?: 'Admin settings';
        $settingsSubtitle = trim($__env->yieldContent('settings-subtitle'))
            ?: 'Manage one section at a time. You will only see settings you can access.';
    @endphp

    <x-header :title="$settingsTitle" separator use-h1>
        <x-slot:subtitle>{{ $settingsSubtitle }}</x-slot:subtitle>
    </x-header>

    <div class="space-y-6">
        <nav class="nexus-panel overflow-x-auto" aria-label="Admin settings sections">
            <div class="tabs tabs-border min-w-max px-2 sm:px-3">
                <a
                    href="{{ route('admin.settings') }}"
                    class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings') ? 'tab-active' : '' }}"
                    @if (request()->routeIs('admin.settings')) aria-current="page" @endif
                >Directory</a>

                @can('view-diagnostic-info')
                    <a href="{{ route('admin.settings.public-site') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.public-site') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.public-site')) aria-current="page" @endif>Public site</a>
                    <a href="{{ route('admin.settings.discord.index') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.discord.index') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.discord.index')) aria-current="page" @endif>Discord</a>
                @endcan

                @canany(['manage-accounts', 'manage-loans', 'manage-grants'])
                    <a href="{{ route('admin.settings.finance-policy') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.finance-policy') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.finance-policy')) aria-current="page" @endif>Finance policy</a>
                @endcanany

                @canany(['view-diagnostic-info', 'edit-users'])
                    <a href="{{ route('admin.settings.security-retention') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.security-retention') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.security-retention')) aria-current="page" @endif>Security &amp; retention</a>
                @endcanany

                @can('view-diagnostic-info')
                    <a href="{{ route('admin.settings.data-sync') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.data-sync') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.data-sync')) aria-current="page" @endif>Data sync</a>
                    <a href="{{ route('admin.settings.recovery') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.recovery') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.recovery')) aria-current="page" @endif>Recovery</a>
                    <a href="{{ route('admin.settings.system-health') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.settings.system-health') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.settings.system-health')) aria-current="page" @endif>System health</a>
                @endcan

                @can('view-federation')
                    <a href="{{ route('admin.federation.index') }}" class="tab h-12 whitespace-nowrap {{ request()->routeIs('admin.federation.*') ? 'tab-active' : '' }}" @if (request()->routeIs('admin.federation.*')) aria-current="page" @endif>Federation</a>
                @endcan
            </div>
        </nav>

        @yield('settings-content')
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (!window.location.hash) {
                    return;
                }

                let targetId;

                try {
                    targetId = decodeURIComponent(window.location.hash.slice(1));
                } catch {
                    return;
                }

                const target = document.getElementById(targetId);

                if (!target) {
                    return;
                }

                const disclosure = target.matches('[data-settings-disclosure]')
                    ? target
                    : target.closest('[data-settings-disclosure]');

                if (disclosure) {
                    disclosure.open = true;
                }

                window.requestAnimationFrame(() => {
                    target.scrollIntoView({
                        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                        block: 'start',
                    });
                });
            });
        </script>
    @endpush
@endsection
