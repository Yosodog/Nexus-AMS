<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? env("APP_NAME") }}</title>
    <link rel="icon" href="{{ $faviconUrl }}">
    <x-theme-script />
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="flex min-h-screen flex-col bg-base-200/30 overflow-x-hidden">

    @if($pwApiDown)
        <div class="bg-warning text-warning-content text-sm py-1 text-center w-full">
            Nexus has detected PW API issues. Functionality will be limited.
            @if(!empty($pwApiLastChecked))
                <span class="opacity-75 ml-2">
                    (Last checked {{ \Carbon\Carbon::parse($pwApiLastChecked)->diffForHumans() }})
                </span>
            @endif
        </div>
    @endif

    <x-header />

    <div class="flex-grow relative">
        <div class="absolute inset-0 bg-gradient-to-b from-primary/5 via-base-200/30 to-base-100 pointer-events-none">
        </div>
        <div class="container relative mx-auto max-w-7xl px-3 py-6 sm:px-4 sm:py-8 xl:max-w-6xl 2xl:max-w-[1400px]">
            <div class="flex">
                <main class="w-full min-w-0 space-y-6">
                    @if (session('alert-message') || $errors->any())
                        <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
                    @endif
                    @yield('content')
                    <!-- Toast Notification -->
                    <div id="toast-container"
                        class="toast toast-center toast-bottom sm:toast-end fixed bottom-4 left-1/2 -translate-x-1/2 sm:left-auto sm:right-6 sm:bottom-6 sm:translate-x-0 px-4 sm:px-0 z-50 hidden pointer-events-none flex flex-col gap-3 w-full sm:w-auto"
                        aria-live="polite"></div>
                </main>
            </div>
        </div>
    </div>

    <x-footer />



    <script src="//unpkg.com/alpinejs" defer></script>

    @stack('scripts')

</body>

</html>
