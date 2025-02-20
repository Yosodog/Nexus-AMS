<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'NexusAMS' }}</title>
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
</head>
<body class="flex flex-col min-h-screen">

<x-header />

<div class="container mx-auto py-8 flex-grow">
    <div class="flex">
        <main class="w-full">
            @if (session('alert-message') || $errors->any())
                <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
            @endif
            @yield('content')
                <!-- Toast Notification -->
                <div id="toast-container" class="toast toast-end hidden"></div>
        </main>
    </div>
</div>

<x-footer />
</body>
</html>
