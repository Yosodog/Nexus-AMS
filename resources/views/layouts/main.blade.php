<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'NexusAMS' }}</title>
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
</head>
<body>

<x-header />

<div class="container mx-auto py-8">
    <div class="flex">
        <main class="w-full">
            @yield('content')
        </main>
    </div>
</div>

<x-footer />
</body>
</html>
