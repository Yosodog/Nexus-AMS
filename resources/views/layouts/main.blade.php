<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="{{ $faviconUrl }}">

    <x-theme-init />
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>

<body data-surface="member" class="app-frame overflow-x-hidden">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <x-system-status-banner :down="$pwApiDown" :checked-at="$pwApiLastChecked" />

    <livewire:app-header />

    <div class="member-frame">
        <div class="app-content">
            <main id="main-content" class="nexus-stack min-w-0" tabindex="-1">
                    @if (session('alert-message') || $errors->any())
                        <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
                    @endif
                    @yield('content')
                    <div id="toast-container"
                        class="toast toast-center toast-bottom sm:toast-end hidden pointer-events-none"
                        aria-live="polite" aria-atomic="true"></div>
            </main>
        </div>
    </div>

    <x-footer />

    @livewireScripts
    @stack('scripts')

</body>

</html>
