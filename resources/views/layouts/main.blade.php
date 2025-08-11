<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'NexusAMS' }}</title>
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex flex-col min-h-screen">

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

<x-header/>

<div class="container mx-auto py-8 flex-grow">
    <div class="flex">
        <main class="w-full">
            @if (session('alert-message') || $errors->any())
                <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}"/>
            @endif
            @yield('content')
            <!-- Toast Notification -->
            <div id="toast-container" class="toast toast-end hidden"></div>
        </main>
    </div>
</div>

<x-footer/>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const themeToggle = document.getElementById("theme-toggle");

        // Load saved theme from local storage
        if (localStorage.getItem("theme") === "night") {
            document.documentElement.setAttribute("data-theme", "night");
            themeToggle.checked = true;
        }

        // Listen for toggle changes
        themeToggle.addEventListener("change", function () {
            if (this.checked) {
                document.documentElement.setAttribute("data-theme", "night");
                localStorage.setItem("theme", "night");
            } else {
                document.documentElement.removeAttribute("data-theme");
                localStorage.setItem("theme", "light");
            }
        });
    });
</script>

<script src="//unpkg.com/alpinejs" defer></script>

@stack('scripts')

</body>
</html>
