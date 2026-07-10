<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>@yield('code') · @yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">

    <x-theme-init />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-surface="public" class="min-h-dvh bg-base-100 text-base-content antialiased">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <header class="border-b border-base-300 bg-base-100">
        <div class="mx-auto flex min-h-16 w-full max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="public-brand" aria-label="{{ config('app.name') }} home">
                <span class="public-brand__mark" aria-hidden="true">N</span>
                <span class="min-w-0">
                    <span class="public-brand__name">{{ config('app.name') }}</span>
                    <span class="public-brand__descriptor">Alliance operations</span>
                </span>
            </a>
            <x-theme-picker />
        </div>
    </header>

    <main id="main-content" class="mx-auto flex min-h-[calc(100dvh-8rem)] w-full max-w-7xl items-center px-4 py-12 sm:px-6 lg:px-8" tabindex="-1">
        <div class="grid w-full gap-10 lg:grid-cols-[12rem_minmax(0,38rem)] lg:items-start">
            <div aria-hidden="true" class="font-display text-[7rem] font-bold leading-none tracking-tight text-primary/25 sm:text-[10rem]">
                @yield('code')
            </div>

            <section aria-labelledby="error-title" class="border-t-2 border-primary pt-6">
                <p class="nexus-eyebrow">System response · @yield('code')</p>
                <h1 id="error-title" class="mt-3 font-display text-4xl font-bold leading-none text-base-content sm:text-5xl">@yield('heading')</h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-base-content/75">@yield('message')</p>

                @hasSection('preserved')
                    <div class="mt-6 border-l border-info bg-info/8 px-4 py-3 text-sm leading-6 text-base-content/75">
                        <span class="font-semibold text-base-content">Your work:</span>
                        @yield('preserved')
                    </div>
                @endif

                <div class="mt-7 flex flex-wrap gap-3">
                    @yield('actions')
                    <a href="{{ url('/') }}" class="btn btn-ghost">Return home</a>
                </div>

                @hasSection('support')
                    <p class="mt-7 text-sm leading-6 text-base-content/60">@yield('support')</p>
                @endif
            </section>
        </div>
    </main>

    <footer class="border-t border-base-300 px-4 py-5 text-center text-xs text-base-content/55">
        {{ config('app.name') }} · Safe, permission-aware alliance operations
    </footer>
</body>
</html>
