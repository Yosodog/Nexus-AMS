<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', $title ?? config('app.name'))</title>
    <link rel="icon" href="{{ $faviconUrl ?? asset('favicon.ico') }}">

    <x-theme-init />
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body data-surface="public" class="public-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <x-system-status-banner :down="$pwApiDown ?? false" :checked-at="$pwApiLastChecked ?? null" />

    <header class="public-nav">
        <div class="public-nav__inner">
            <a href="{{ route('home') }}" class="public-brand" aria-label="{{ config('app.name') }} home">
                <span class="public-brand__mark" aria-hidden="true">N</span>
                <span class="min-w-0">
                    <span class="public-brand__name">{{ config('app.name') }}</span>
                    <span class="public-brand__descriptor">Alliance operations</span>
                </span>
            </a>

            <nav class="public-nav__links" aria-label="Public navigation">
                <a href="{{ route('home') }}" @class(['public-nav__link', 'is-active' => request()->routeIs('home')]) @if(request()->routeIs('home')) aria-current="page" @endif>Overview</a>
                <a href="{{ route('apply.show') }}" @class(['public-nav__link', 'is-active' => request()->routeIs('apply.show')]) @if(request()->routeIs('apply.show')) aria-current="page" @endif>Apply</a>
            </nav>

            <div class="public-nav__actions">
                <x-theme-picker />
                @auth
                    <a href="{{ route('user.dashboard') }}" class="btn btn-primary btn-sm">Open member app</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost btn-sm public-nav__desktop-action">Sign in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm public-nav__desktop-action">Create account</a>
                @endauth

                <details class="public-nav__mobile">
                    <summary class="btn btn-ghost btn-circle btn-sm" aria-label="Open navigation">
                        <x-icon name="o-bars-3" class="size-5" />
                    </summary>
                    <nav class="public-nav__mobile-menu" aria-label="Mobile navigation">
                        <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Overview</a>
                        <a href="{{ route('apply.show') }}" @if(request()->routeIs('apply.show')) aria-current="page" @endif>Apply</a>
                        @auth
                            <a href="{{ route('user.dashboard') }}">Open member app</a>
                        @else
                            <a href="{{ route('login') }}">Sign in</a>
                            <a href="{{ route('register') }}">Create account</a>
                        @endauth
                    </nav>
                </details>
            </div>
        </div>
    </header>

    <main id="main-content" class="public-main" tabindex="-1">
        @if(session('alert-message'))
            <div class="public-main__notice">
                <x-utils.alert type="{{ session('alert-type') }}" message="{{ session('alert-message') }}" />
            </div>
        @endif

        @yield('content')
    </main>

    <x-footer />

    <x-confirmation-dialog />

    <div id="toast-container" class="toast toast-end toast-bottom hidden" aria-live="polite" aria-atomic="true"></div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
