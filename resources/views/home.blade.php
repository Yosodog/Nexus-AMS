@extends('layouts.public')

@section('title', ($allianceName ?? config('app.name')) . ' | ' . config('app.name'))

@section('content')
    @php
        $stats = $publicStats ?? [];
        $name = $allianceName ?? config('app.name');
        $headline = $homeContent['headline'] ?? $name;
        $tagline = $homeContent['tagline'] ?? '';
        $about = $homeContent['about'] ?? '';
        $highlights = $homeContent['highlights'] ?? [];
        $highlights = is_array($highlights) ? $highlights : [];
        $statsIntro = $homeContent['stats_intro'] ?? 'A current view of the alliance.';
        $closingText = $homeContent['closing_text'] ?? '';
        $heroBadge = $homeContent['hero_badge'] ?? '';
        $ctaLabel = $homeContent['cta_label'] ?? 'Apply';

        $discordLink = $stats['discord_link'] ?? null;
        $forumLink = $stats['forum_link'] ?? null;
        $wikiLink = $stats['wiki_link'] ?? null;

        $primaryLink = null;
        $primaryLinkLabel = null;

        if (! empty($discordLink)) {
            $primaryLink = $discordLink;
            $primaryLinkLabel = 'Join our Discord';
        } elseif (! empty($forumLink)) {
            $primaryLink = $forumLink;
            $primaryLinkLabel = 'Visit our forum';
        } elseif (! empty($wikiLink)) {
            $primaryLink = $wikiLink;
            $primaryLinkLabel = 'Read our guide';
        }

        $formatInteger = static fn (mixed $value): ?string => is_numeric($value)
            ? number_format((int) $value)
            : null;
        $formatDecimal = static fn (mixed $value): ?string => is_numeric($value)
            ? number_format((float) $value, 1)
            : null;

        $memberCount = $formatInteger($stats['members'] ?? null);
        $allianceScore = $formatDecimal($stats['score'] ?? null);
        $averageScore = $formatDecimal($stats['avgScore'] ?? null);
        $globalRank = $formatInteger($stats['rank'] ?? null);
        $totalCities = $formatInteger($stats['totalCities'] ?? null);
        $warsWon = $formatInteger($stats['totalWarsWon'] ?? null);
        $winRate = $formatDecimal($stats['winRate'] ?? null);
        $population = $formatInteger($stats['totalPopulation'] ?? null);
    @endphp

    <section
        class="overflow-hidden border-y border-base-300 bg-base-100"
        aria-labelledby="home-heading"
    >
        <div class="grid lg:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]">
            <div class="flex flex-col justify-center px-5 py-10 sm:px-8 sm:py-14 lg:px-12 lg:py-20 xl:px-16">
                @if(filled($heroBadge))
                    <p class="nexus-status mb-6">{{ $heroBadge }}</p>
                @endif

                <h1
                    id="home-heading"
                    class="nexus-page-title max-w-4xl text-balance text-4xl sm:text-5xl lg:text-6xl"
                >
                    {{ $headline }}
                </h1>

                @if(filled($tagline))
                    <p class="nexus-page-summary mt-6 max-w-3xl text-pretty text-base sm:text-lg">
                        {{ $tagline }}
                    </p>
                @endif

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <a
                        href="{{ route('apply.show') }}"
                        class="btn btn-primary sm:btn-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    >
                        {{ $ctaLabel }}
                        <x-icon name="o-arrow-right" class="size-4" aria-hidden="true" />
                    </a>

                    @if($primaryLink)
                        <a
                            href="{{ $primaryLink }}"
                            class="btn btn-ghost sm:btn-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ $primaryLinkLabel }}
                            <x-icon name="o-arrow-top-right-on-square" class="size-4" aria-hidden="true" />
                        </a>
                    @endif
                </div>
            </div>

            <aside
                id="alliance-profile"
                class="border-t border-base-300 bg-base-200/60 px-5 py-8 sm:px-8 lg:border-l lg:border-t-0 lg:px-10 lg:py-12"
                aria-labelledby="alliance-profile-heading"
            >
                <div class="flex items-center gap-4 border-b border-base-300 pb-6">
                    <div class="grid aspect-[3/2] w-24 shrink-0 place-items-center overflow-hidden border border-base-300 bg-base-100 sm:w-28">
                        @if(! empty($stats['flag']))
                            <img
                                src="{{ $stats['flag'] }}"
                                alt="{{ $name }} alliance flag"
                                class="h-full w-full object-contain"
                                loading="eager"
                                decoding="async"
                            >
                        @else
                            <span class="font-bold text-base-content/55" aria-hidden="true">
                                {{ \Illuminate\Support\Str::of($name)->substr(0, 2)->upper() }}
                            </span>
                        @endif
                    </div>

                    <div class="min-w-0">
                        <p class="nexus-kicker">Alliance brief</p>
                        <h2 id="alliance-profile-heading" class="nexus-section-title mt-2 break-words text-2xl">
                            {{ $name }}
                        </h2>
                    </div>
                </div>

                @if(filled($about))
                    <p class="mt-6 max-w-prose text-pretty text-sm leading-7 text-base-content/75 sm:text-base">
                        {{ $about }}
                    </p>
                @endif

                @if(count($highlights) > 0)
                    <div class="mt-7">
                        <h3 class="text-sm font-bold text-base-content">What members can expect</h3>
                        <ul class="mt-4 grid gap-3" role="list">
                            @foreach($highlights as $highlight)
                                @if(is_string($highlight) && filled($highlight))
                                    <li class="flex items-start gap-3 text-sm leading-6 text-base-content/70">
                                        <x-icon name="o-check" class="mt-1 size-4 shrink-0 text-primary" aria-hidden="true" />
                                        <span>{{ $highlight }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($discordLink || $forumLink || $wikiLink)
                    <nav class="mt-7 border-t border-base-300 pt-5" aria-label="Alliance links">
                        <p class="text-xs font-bold text-base-content/55">Alliance links</p>
                        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-3">
                            @if($discordLink)
                                <a
                                    href="{{ $discordLink }}"
                                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Discord
                                    <x-icon name="o-arrow-top-right-on-square" class="size-3.5" aria-hidden="true" />
                                </a>
                            @endif

                            @if($forumLink)
                                <a
                                    href="{{ $forumLink }}"
                                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Forum
                                    <x-icon name="o-arrow-top-right-on-square" class="size-3.5" aria-hidden="true" />
                                </a>
                            @endif

                            @if($wikiLink)
                                <a
                                    href="{{ $wikiLink }}"
                                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Guide
                                    <x-icon name="o-arrow-top-right-on-square" class="size-3.5" aria-hidden="true" />
                                </a>
                            @endif
                        </div>
                    </nav>
                @endif
            </aside>
        </div>
    </section>

    @if($alliance ?? false)
        <section aria-labelledby="alliance-record-heading">
            <header class="nexus-page-header">
                <div class="nexus-page-header__copy">
                    <p class="nexus-kicker">Public alliance record</p>
                    <h2 id="alliance-record-heading" class="nexus-section-title text-2xl sm:text-3xl">
                        {{ $name }} at a glance
                    </h2>
                    @if(filled($statsIntro))
                        <p class="nexus-page-summary">{{ $statsIntro }}</p>
                    @endif
                </div>
            </header>

            <dl class="mt-6 grid grid-cols-2 overflow-hidden border border-base-300 bg-base-100 md:grid-cols-4">
                <div class="border-b border-r border-base-300 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Member nations</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($memberCount !== null)
                            {{ $memberCount }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="border-b border-base-300 p-4 sm:p-5 md:border-r">
                    <dt class="nexus-stat-label">Alliance score</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($allianceScore !== null)
                            {{ $allianceScore }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="border-b border-r border-base-300 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Average score</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($averageScore !== null)
                            {{ $averageScore }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="border-b border-base-300 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Global rank</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($globalRank !== null)
                            <span aria-hidden="true">#</span>{{ $globalRank }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="border-b border-r border-base-300 p-4 sm:p-5 md:border-b-0">
                    <dt class="nexus-stat-label">Cities</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($totalCities !== null)
                            {{ $totalCities }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="border-b border-base-300 p-4 sm:p-5 md:border-b-0 md:border-r">
                    <dt class="nexus-stat-label">Wars won</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($warsWon !== null)
                            {{ $warsWon }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="border-r border-base-300 p-4 sm:p-5">
                    <dt class="nexus-stat-label">War win rate</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($winRate !== null)
                            {{ $winRate }}<span aria-hidden="true">%</span><span class="sr-only"> percent</span>
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>

                <div class="p-4 sm:p-5">
                    <dt class="nexus-stat-label">Population</dt>
                    <dd class="nexus-stat-value mt-2">
                        @if($population !== null)
                            {{ $population }}
                        @else
                            <span aria-hidden="true">&mdash;</span><span class="sr-only">Not available</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>
    @endif

    <section
        class="border-y border-neutral-content/20 bg-neutral text-neutral-content"
        aria-labelledby="recruitment-heading"
    >
        <div class="grid gap-8 px-5 py-9 sm:px-8 sm:py-12 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:px-12">
            <div class="max-w-3xl">
                <h2 id="recruitment-heading" class="text-balance font-bold text-2xl sm:text-3xl">
                    Interested in joining {{ $name }}?
                </h2>
                @if(filled($closingText))
                    <p class="mt-3 max-w-2xl text-pretty text-sm leading-7 text-neutral-content/75 sm:text-base">
                        {{ $closingText }}
                    </p>
                @endif
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center lg:justify-end">
                <a
                    href="{{ route('apply.show') }}"
                    class="btn btn-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-content"
                >
                    {{ $ctaLabel }}
                    <x-icon name="o-arrow-right" class="size-4" aria-hidden="true" />
                </a>

                @guest
                    <a
                        href="{{ route('login') }}"
                        class="btn btn-ghost border-neutral-content/25 text-neutral-content hover:border-neutral-content/40 hover:bg-neutral-content/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-content"
                    >
                        Sign in
                    </a>
                @endguest
            </div>
        </div>
    </section>
@endsection
