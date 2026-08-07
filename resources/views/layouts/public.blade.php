<!DOCTYPE html>
<html lang="en">
<head>
    @php
        $resolvedSeo = $seo ?? null;
        $publicSiteName = $resolvedSeo?->siteName ?? config('app.name');
    @endphp
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="application-name" content="{{ config('app.name') }}">
    <title>@if($resolvedSeo){{ $resolvedSeo->title }}@else@yield('title', $title ?? config('app.name'))@endif</title>
    <meta name="robots" content="{{ $resolvedSeo?->robots ?? 'noindex, nofollow' }}">

    @if($resolvedSeo)
        <meta name="description" content="{{ $resolvedSeo->description }}">
        <link rel="canonical" href="{{ $resolvedSeo->canonical }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $resolvedSeo->siteName }}">
        <meta property="og:title" content="{{ $resolvedSeo->title }}">
        <meta property="og:description" content="{{ $resolvedSeo->description }}">
        <meta property="og:url" content="{{ $resolvedSeo->canonical }}">
        <meta name="twitter:card" content="{{ $resolvedSeo->twitterCard() }}">
        <meta name="twitter:title" content="{{ $resolvedSeo->title }}">
        <meta name="twitter:description" content="{{ $resolvedSeo->description }}">

        @if($resolvedSeo->imageUrl)
            <meta property="og:image" content="{{ $resolvedSeo->imageUrl }}">
            <meta property="og:image:alt" content="{{ $resolvedSeo->imageAlt }}">
            <meta name="twitter:image" content="{{ $resolvedSeo->imageUrl }}">
            <meta name="twitter:image:alt" content="{{ $resolvedSeo->imageAlt }}">
        @endif

        @if($resolvedSeo->structuredData)
            <script type="application/ld+json">{!! Illuminate\Support\Js::encode($resolvedSeo->structuredData) !!}</script>
        @endif
    @endif

    <link rel="icon" href="{{ $faviconUrl ?? asset('favicon.ico') }}">

    <x-theme-init />
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body data-surface="public" class="public-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <x-system-status-banner :down="$pwApiDown ?? false" :checked-at="$pwApiLastChecked ?? null" />
    <x-async.global-status />

    <header class="public-nav">
        <div class="public-nav__inner">
            <a href="{{ route('home') }}" class="public-brand" aria-label="{{ $publicSiteName }} home">
                <span class="public-brand__mark" aria-hidden="true">{{ Str::of($publicSiteName)->substr(0, 1)->upper() }}</span>
                <span class="min-w-0">
                    <span class="public-brand__name">{{ $publicSiteName }}</span>
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
                    <a href="{{ route('apply.show') }}" class="btn btn-primary btn-sm public-nav__desktop-action">Apply to join</a>
                @endauth

                <details class="public-nav__mobile">
                    <summary class="btn btn-ghost btn-circle btn-sm nexus-icon-button tooltip" aria-label="Open navigation" data-tip="Open navigation">
                        <x-icon name="o-bars-3" class="size-5" />
                    </summary>
                    <nav class="public-nav__mobile-menu" aria-label="Mobile navigation">
                        <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Overview</a>
                        <a href="{{ route('apply.show') }}" @if(request()->routeIs('apply.show')) aria-current="page" @endif>Apply</a>
                        @auth
                            <a href="{{ route('user.dashboard') }}">Open member app</a>
                        @else
                            <a href="{{ route('login') }}">Sign in</a>
                            <a href="{{ route('apply.member-registration') }}">Existing member registration</a>
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

    <x-footer :site-name="$publicSiteName" />

    <x-confirmation-dialog />

    <div id="toast-container" class="toast toast-end toast-bottom hidden" aria-live="polite" aria-atomic="true"></div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
