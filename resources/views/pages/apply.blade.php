@extends('layouts.public')

@section('title', 'Apply · ' . config('app.name'))

@section('content')
    <section class="border-b border-base-300 bg-base-200/55">
        <div class="mx-auto grid w-full max-w-7xl gap-8 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[minmax(0,1fr)_20rem] lg:px-8">
            <div class="max-w-3xl">
                <p class="nexus-kicker">Alliance application</p>
                <h1 class="mt-3 text-balance font-display text-4xl font-bold leading-none text-base-content sm:text-5xl">
                    Apply to {{ $allianceName }}
                </h1>
                <p class="mt-5 max-w-2xl text-pretty text-base leading-7 text-base-content/75">
                    Applications start in Politics &amp; War and finish in Discord. You do not need a Nexus account to apply; the application bot connects your nation ID to your Discord identity.
                </p>

                <div class="mt-7 flex flex-wrap items-center gap-3">
                    @if($applicationStartUrl)
                        <a
                            href="{{ $applicationStartUrl }}"
                            class="btn btn-primary"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Start in Politics &amp; War
                            <x-icon name="o-arrow-top-right-on-square" class="size-4" aria-hidden="true" />
                        </a>
                        <span class="text-sm leading-6 text-base-content/65">Opens the official alliance join screen.</span>
                    @elseif(! $applicationsOpen)
                        <div class="alert alert-warning items-start" role="status">
                            <x-icon name="o-information-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <p class="text-sm leading-6">Applications are currently paused. You can still review the requirements below and check back later.</p>
                        </div>
                    @else
                        <div class="alert alert-warning items-start" role="status">
                            <x-icon name="o-information-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <p class="text-sm leading-6">The alliance join link is not available right now. Follow the published instructions below to contact recruitment staff.</p>
                        </div>
                    @endif
                </div>
            </div>

            <aside class="border-t border-base-300 pt-5 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0" aria-labelledby="existing-member-heading">
                <h2 id="existing-member-heading" class="font-display text-xl font-bold text-base-content">Already an alliance member?</h2>
                @auth
                    <p class="mt-3 text-sm leading-6 text-base-content/70">Your account is already set up. Open the member app to continue.</p>
                    <a href="{{ route('user.dashboard') }}" class="btn btn-secondary btn-sm mt-5">Open member app</a>
                @else
                    <p class="mt-3 text-sm leading-6 text-base-content/70">
                        Member registration is only for nations already accepted into the alliance. Applicants should use the process on this page.
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ $existingMemberRegistrationUrl }}" class="btn btn-secondary btn-sm">Register as a member</a>
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
                    </div>
                @endauth
            </aside>
        </div>
    </section>

    <section class="apply-page-shell pt-10 sm:pt-12" aria-labelledby="application-path-heading">
        <div class="mb-6 max-w-3xl">
            <h2 id="application-path-heading" class="text-balance font-display text-3xl font-bold text-base-content">How to submit your application</h2>
            <p class="mt-3 text-pretty leading-7 text-base-content/70">Complete these steps in order. The bot will not create an application until your nation is eligible.</p>
        </div>

        <ol class="border-y border-base-300 bg-base-100 lg:grid lg:grid-cols-3 lg:divide-x lg:divide-base-300" role="list">
            <li class="border-b border-base-300 p-6 lg:border-b-0">
                <div class="flex items-center gap-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-primary font-bold text-primary-content" aria-hidden="true">1</span>
                    <h3 class="font-display text-xl font-bold text-base-content">Become an Applicant in-game</h3>
                </div>
                <p class="mt-4 text-sm leading-6 text-base-content/70">
                    Use the official alliance join screen. Your Politics &amp; War nation must appear in {{ $allianceName }} with the Applicant position before the bot can accept it.
                </p>
                @if($applicationStartUrl)
                    <a href="{{ $applicationStartUrl }}" class="link link-primary mt-4 inline-flex font-semibold" target="_blank" rel="noopener noreferrer">Open the alliance join screen</a>
                @endif
            </li>

            <li class="border-b border-base-300 p-6 lg:border-b-0">
                <div class="flex items-center gap-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-primary font-bold text-primary-content" aria-hidden="true">2</span>
                    <h3 class="font-display text-xl font-bold text-base-content">Join the alliance Discord</h3>
                </div>
                <p class="mt-4 text-sm leading-6 text-base-content/70">
                    Use the Discord account you want recruitment staff to recognize. That Discord identity owns the application and its status.
                </p>
                @if($discordUrl)
                    <a href="{{ $discordUrl }}" class="link link-primary mt-4 inline-flex font-semibold" target="_blank" rel="noopener noreferrer">Join {{ $allianceName }} on Discord</a>
                @else
                    <p class="mt-4 text-sm font-semibold text-base-content">Ask recruitment staff for the official Discord invite before continuing.</p>
                @endif
            </li>

            <li class="p-6">
                <div class="flex items-center gap-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-primary font-bold text-primary-content" aria-hidden="true">3</span>
                    <h3 class="font-display text-xl font-bold text-base-content">Run the application command</h3>
                </div>
                <p class="mt-4 text-sm leading-6 text-base-content/70">
                    In Discord, run <code class="rounded bg-base-200 px-1.5 py-1 font-mono text-xs text-base-content">/apply nationid:&lt;your nation ID&gt;</code>. If your nation is not eligible, the bot will explain what needs to change without submitting it.
                </p>
            </li>
        </ol>

        <div class="mt-6 flex items-start gap-3 border-b border-base-300 pb-8 text-sm leading-6 text-base-content/70">
            <x-icon name="o-chat-bubble-left-right" class="mt-1 size-5 shrink-0 text-primary" aria-hidden="true" />
            <p>
                <span class="font-semibold text-base-content">What happens next:</span>
                the bot creates the configured interview workflow for recruitment staff. Continue there, then use <code class="rounded bg-base-200 px-1.5 py-1 font-mono text-xs text-base-content">/applications status</code> in Discord to check progress.
            </p>
        </div>
    </section>

    <section class="apply-page-shell" aria-labelledby="application-briefing-heading">
        <article class="apply-page-content">
            <header class="mb-8 border-b border-base-300 pb-5">
                <p class="nexus-kicker">Published by recruitment staff</p>
                <h2 id="application-briefing-heading" class="mt-2 text-balance font-display text-3xl font-bold text-base-content">Requirements and policies</h2>
            </header>

            @if(filled($content))
                <div class="apply-page-richtext">
                    {!! $content !!}
                </div>
            @else
                <p class="max-w-3xl text-pretty leading-7 text-base-content/70">No additional requirements have been published. Recruitment staff may provide current eligibility details in Discord.</p>
            @endif
        </article>
    </section>
@endsection
