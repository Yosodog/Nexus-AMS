<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name').' - Admin')</title>
    <link rel="icon" href="{{ $faviconUrl }}">

    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body class="min-h-screen bg-base-200/40 font-sans">

    {{-- Top Navigation --}}
    <livewire:admin.app-navbar />

    {{-- Main with Sidebar --}}
    <x-main full-width with-nav>
        <x-slot:sidebar drawer="admin-sidebar" collapsible class="bg-base-100 border-r border-base-300 pt-2">
            <livewire:admin.app-sidebar />
        </x-slot:sidebar>

        <x-slot:content class="min-w-0 overflow-x-hidden px-4 py-5 lg:px-6 lg:py-6">
            @if (session('alert-message') || $errors->any())
                <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
            @endif

            @yield('content')
        </x-slot:content>
    </x-main>

    @livewireScripts
    @stack('modals')
    @stack('scripts')
    @yield('scripts')

</body>

</html>
