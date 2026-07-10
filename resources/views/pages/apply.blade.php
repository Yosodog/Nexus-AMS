@extends('layouts.public')

@section('title', 'Apply · ' . config('app.name'))

@section('content')
    <section class="border-b border-base-300 bg-base-200/55">
        <div class="mx-auto grid w-full max-w-7xl gap-8 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[minmax(0,1fr)_20rem] lg:px-8">
            <div class="max-w-3xl">
                <p class="nexus-eyebrow">Membership application</p>
                <h1 class="mt-3 font-display text-4xl font-bold leading-none text-base-content sm:text-5xl">
                    Your path into {{ config('app.name') }}
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-base-content/70">
                    Review the current requirements and application instructions before creating an account. The record below is maintained by alliance staff.
                </p>
            </div>

            <aside class="border-t border-base-300 pt-5 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0" aria-label="Application next steps">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-base-content/55">Ready to continue?</p>
                <p class="mt-2 text-sm leading-6 text-base-content/70">Create an account to begin, or sign in to resume an application already in progress.</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @auth
                        <a href="{{ route('user.dashboard') }}" class="btn btn-primary btn-sm">Open member app</a>
                    @else
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Create account</a>
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
                    @endauth
                </div>
            </aside>
        </div>
    </section>

    <section class="apply-page-shell" aria-labelledby="application-briefing-heading">
        <article class="apply-page-content">
            <header class="mb-8 border-b border-base-300 pb-5">
                <p class="nexus-eyebrow">Official briefing</p>
                <h2 id="application-briefing-heading" class="mt-2 font-display text-3xl font-bold text-base-content">Requirements and process</h2>
            </header>

            <div class="apply-page-richtext">
                {!! $content !!}
            </div>
        </article>
    </section>
@endsection
