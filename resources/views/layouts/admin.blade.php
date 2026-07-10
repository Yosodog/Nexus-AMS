<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', config('app.name').' - Admin')</title>
    <link rel="icon" href="{{ $faviconUrl }}">

    <x-theme-init />
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body data-surface="admin" class="admin-app">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <x-system-status-banner :down="$pwApiDown" :checked-at="$pwApiLastChecked" />
    <livewire:admin.app-navbar />

    <x-main full-width with-nav class="admin-shell">
        <x-slot:sidebar drawer="admin-sidebar" collapsible class="admin-sidebar">
            <livewire:admin.app-sidebar />
        </x-slot:sidebar>

        <x-slot:content class="admin-content min-w-0 overflow-x-hidden">
            <main id="main-content" class="admin-content__inner nexus-stack" tabindex="-1">
                @if (session('alert-message') || $errors->any())
                    <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
                @endif

                @yield('content')
            </main>
        </x-slot:content>
    </x-main>

    <x-confirmation-dialog />

    @livewireScripts
    @stack('modals')
    @stack('scripts')
    @yield('scripts')

</body>

</html>
