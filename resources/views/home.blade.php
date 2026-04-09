@extends('layouts.main')

@section("content")
    @php
        $stats = $publicStats ?? [];
        $headline = $homeContent['headline'] ?? ($allianceName ?? config('app.name'));
        $tagline = $homeContent['tagline'] ?? '';
        $about = $homeContent['about'] ?? '';
        $highlights = $homeContent['highlights'] ?? [];
        $highlights = is_array($highlights) ? $highlights : [];
        $appLabel = $appName ?? config('app.name');
        $statsIntro = $homeContent['stats_intro'] ?? 'A quick look at the alliance as it stands today.';
        $closingText = $homeContent['closing_text'] ?? "If {$allianceName} feels like the right fit, send in your application and come meet the team.";
        $heroBadge = $homeContent['hero_badge'] ?? 'Recruiting now';
        $ctaLabel = $homeContent['cta_label'] ?? 'Start your application';

        $metaStats = [
            [
                'label' => 'Members',
                'value' => $stats['members'] ?? null,
                'hint' => 'Active nations in the roster',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>',
                'gradient' => 'from-primary/20 to-primary/5',
                'accent' => 'text-primary',
                'ring' => 'ring-primary/20',
                'format' => 'int',
            ],
            [
                'label' => 'Alliance Score',
                'value' => $stats['score'] ?? null,
                'hint' => 'Combined strength of the alliance',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z" clip-rule="evenodd"/></svg>',
                'gradient' => 'from-secondary/20 to-secondary/5',
                'accent' => 'text-secondary',
                'ring' => 'ring-secondary/20',
                'format' => 'float',
            ],
            [
                'label' => 'Avg. Score',
                'value' => $stats['avgScore'] ?? null,
                'hint' => 'Per-nation average across the board',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>',
                'gradient' => 'from-accent/20 to-accent/5',
                'accent' => 'text-accent',
                'ring' => 'ring-accent/20',
                'format' => 'float',
            ],
            [
                'label' => 'Global Rank',
                'value' => $stats['rank'] ?? null,
                'hint' => 'Position on the leaderboard',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744L14.146 7.2 17.5 9.134a1 1 0 010 1.732l-3.354 1.935-1.18 4.455a1 1 0 01-1.933 0L9.854 12.8 6.5 10.866a1 1 0 010-1.732l3.354-1.935 1.18-4.455A1 1 0 0112 2z" clip-rule="evenodd"/></svg>',
                'gradient' => 'from-success/20 to-success/5',
                'accent' => 'text-success',
                'ring' => 'ring-success/20',
                'format' => 'int',
            ],
        ];

        $primaryLink = null;
        $primaryLinkLabel = null;

        if (!empty($stats['discord_link'])) {
            $primaryLink = $stats['discord_link'];
            $primaryLinkLabel = 'Join our Discord';
        } elseif (!empty($stats['forum_link'])) {
            $primaryLink = $stats['forum_link'];
            $primaryLinkLabel = 'Visit our forum';
        } elseif (!empty($stats['wiki_link'])) {
            $primaryLink = $stats['wiki_link'];
            $primaryLinkLabel = 'Read our guide';
        }

        $steps = [
            [
                'number' => '01',
                'title' => 'Apply',
                'description' => 'Fill out a short application. No essay required — just enough to know you are serious.',
                'numClasses' => 'bg-primary/10 text-primary ring-1 ring-primary/20',
            ],
            [
                'number' => '02',
                'title' => 'Get welcomed',
                'description' => 'Meet the community, get oriented, and learn how things run around here.',
                'numClasses' => 'bg-secondary/10 text-secondary ring-1 ring-secondary/20',
            ],
            [
                'number' => '03',
                'title' => 'Start building',
                'description' => 'Access grants, war support, growth programs, and everything else from day one.',
                'numClasses' => 'bg-accent/10 text-accent ring-1 ring-accent/20',
            ],
        ];

        $programs = [
            [
                'title' => 'Grants & Aid',
                'description' => 'Financial support for new and growing nations. Build faster with backing from the alliance.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"/><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"/></svg>',
                'iconClasses' => 'bg-primary/10 text-primary ring-1 ring-primary/15',
            ],
            [
                'title' => 'War Coordination',
                'description' => 'Organized military response when it counts. Clear chains of command and rapid mobilization.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1.944A11.954 11.954 0 012.166 5C2.056 5.649 2 6.319 2 7c0 5.225 3.34 9.67 8 11.317C14.66 16.67 18 12.225 18 7c0-.682-.057-1.35-.166-2.001A11.954 11.954 0 0110 1.944zM11 14a1 1 0 11-2 0 1 1 0 012 0zm0-7a1 1 0 10-2 0v3a1 1 0 102 0V7z" clip-rule="evenodd"/></svg>',
                'iconClasses' => 'bg-error/10 text-error ring-1 ring-error/15',
            ],
            [
                'title' => 'Growth Programs',
                'description' => 'Structured paths to help nations grow. Mentorship, city guides, and build optimization.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z" clip-rule="evenodd"/></svg>',
                'iconClasses' => 'bg-success/10 text-success ring-1 ring-success/15',
            ],
            [
                'title' => 'Active Leadership',
                'description' => 'Government that shows up. Decisions get made, questions get answered, things move forward.',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/></svg>',
                'iconClasses' => 'bg-secondary/10 text-secondary ring-1 ring-secondary/15',
            ],
        ];
    @endphp

    {{-- ═══════════════════════════════════════════════════════════════
         HERO
    ═══════════════════════════════════════════════════════════════ --}}
    <section class="home-hero relative overflow-hidden rounded-[2.5rem] border border-base-300/40 bg-base-100 shadow-2xl">
        {{-- Decorative background --}}
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-24 -top-24 h-[28rem] w-[28rem] rounded-full bg-primary/8 blur-3xl"></div>
            <div class="absolute -right-16 top-1/3 h-[22rem] w-[22rem] rounded-full bg-secondary/8 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/3 h-[18rem] w-[18rem] rounded-full bg-accent/6 blur-3xl"></div>
            <div class="absolute inset-0" style="background-image: radial-gradient(circle at 20% 50%, hsl(var(--p) / 0.04) 0%, transparent 50%), radial-gradient(circle at 80% 20%, hsl(var(--s) / 0.04) 0%, transparent 40%);"></div>
            {{-- Grid pattern overlay --}}
            <div class="absolute inset-0 opacity-[0.03]" style="background-image: linear-gradient(hsl(var(--bc)) 1px, transparent 1px), linear-gradient(90deg, hsl(var(--bc)) 1px, transparent 1px); background-size: 60px 60px;"></div>
        </div>

        <div class="relative px-6 py-10 sm:px-10 sm:py-14 lg:px-14 lg:py-16">
            <div class="grid items-center gap-10 lg:grid-cols-[1.2fr_0.8fr] lg:gap-14">
                {{-- Left: Copy --}}
                <div class="space-y-6">
                    {{-- Badge row --}}
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="home-pulse-badge inline-flex items-center gap-2.5 rounded-full border border-success/30 bg-success/10 px-4 py-2 text-sm font-semibold text-success">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-success"></span>
                            </span>
                            {{ $heroBadge }}
                        </span>
                        @if($alliance)
                            <span class="inline-flex items-center gap-2 rounded-full border border-base-300/60 bg-base-200/60 px-4 py-2 text-sm text-base-content/60">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.828a1 1 0 101.415-1.414L11 9.586V6z" clip-rule="evenodd"/></svg>
                                Live stats
                            </span>
                        @endif
                    </div>

                    {{-- Headline --}}
                    <h1 class="max-w-2xl text-4xl font-black leading-[1.08] tracking-tight sm:text-5xl lg:text-[3.5rem]">
                        {{ $headline }}
                    </h1>

                    {{-- Tagline --}}
                    <p class="max-w-xl text-lg leading-relaxed text-base-content/70">
                        {{ $tagline }}
                    </p>

                    {{-- CTAs --}}
                    <div class="flex flex-wrap items-center gap-4 pt-1">
                        <a href="{{ route('apply.show') }}" class="btn btn-primary btn-lg gap-2 shadow-lg shadow-primary/25 transition-all duration-200 hover:shadow-xl hover:shadow-primary/30 hover:-translate-y-0.5">
                            {{ $ctaLabel }}
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </a>
                        @if($primaryLink)
                            <a href="{{ $primaryLink }}" class="btn btn-ghost btn-lg gap-2 text-base-content/70 hover:text-base-content" target="_blank" rel="noopener">
                                {{ $primaryLinkLabel }}
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>
                            </a>
                        @else
                            <a href="#about" class="btn btn-ghost btn-lg text-base-content/70 hover:text-base-content">
                                Learn more
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            </a>
                        @endif
                    </div>

                    {{-- Quick stats row (compact, inline) --}}
                    @if($alliance)
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-base-300/50 pt-6">
                            @foreach($metaStats as $stat)
                                @php
                                    $value = $stat['value'];
                                    $formatted = '—';
                                    if (is_numeric($value)) {
                                        $formatted = $stat['format'] === 'float'
                                            ? number_format((float) $value, 1)
                                            : number_format((int) $value);
                                    }
                                @endphp
                                <div class="flex items-center gap-2">
                                    <span class="{{ $stat['accent'] }} opacity-60">{!! $stat['icon'] !!}</span>
                                    <div>
                                        <span class="text-lg font-bold {{ $stat['accent'] }}">{{ $formatted }}</span>
                                        <span class="ml-1 text-xs text-base-content/50">{{ $stat['label'] }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Right: Alliance card --}}
                <div class="space-y-4">
                    <div class="rounded-[2rem] border border-base-300/50 bg-gradient-to-br from-base-100 to-base-200/50 p-6 shadow-xl sm:p-7">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0">
                                <div class="h-16 w-16 overflow-hidden rounded-2xl border-2 border-base-300/70 bg-base-200 shadow-md">
                                    @if(!empty($stats['flag']))
                                        <img src="{{ $stats['flag'] }}" alt="{{ $allianceName }} flag" class="h-full w-full object-cover">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary/20 to-secondary/20 text-xl font-black text-base-content/60">
                                            {{ \Illuminate\Support\Str::of($allianceName ?? $appLabel)->substr(0, 2)->upper() }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-base-content/40">Alliance</p>
                                <h2 class="truncate text-2xl font-black">{{ $allianceName }}</h2>
                            </div>
                        </div>

                        @if($about)
                            <p class="mt-5 text-[0.94rem] leading-7 text-base-content/70">
                                {{ $about }}
                            </p>
                        @endif

                        {{-- Highlights --}}
                        @if(count($highlights))
                            <div class="mt-5 space-y-2.5">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-base-content/40">Why people join</p>
                                @foreach($highlights as $highlight)
                                    <div class="flex items-start gap-3 rounded-xl bg-base-200/60 px-4 py-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                        <span class="text-sm leading-6 text-base-content/75">{{ $highlight }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════
         STATS — deeper numbers (different from hero row)
    ═══════════════════════════════════════════════════════════════ --}}
    @if($alliance)
        @php
            $deepStats = [
                [
                    'label' => 'Total Cities',
                    'value' => $stats['totalCities'] ?? null,
                    'hint' => 'Cities built across every member nation',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"/></svg>',
                    'gradient' => 'from-primary/20 to-primary/5',
                    'accent' => 'text-primary',
                    'ring' => 'ring-primary/20',
                    'format' => 'int',
                ],
                [
                    'label' => 'Wars Won',
                    'value' => $stats['totalWarsWon'] ?? null,
                    'hint' => 'Victories on the battlefield, combined',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1.944A11.954 11.954 0 012.166 5C2.056 5.649 2 6.319 2 7c0 5.225 3.34 9.67 8 11.317C14.66 16.67 18 12.225 18 7c0-.682-.057-1.35-.166-2.001A11.954 11.954 0 0110 1.944zM11 14a1 1 0 11-2 0 1 1 0 012 0zm0-7a1 1 0 10-2 0v3a1 1 0 102 0V7z" clip-rule="evenodd"/></svg>',
                    'gradient' => 'from-error/20 to-error/5',
                    'accent' => 'text-error',
                    'ring' => 'ring-error/20',
                    'format' => 'int',
                ],
                [
                    'label' => 'Win Rate',
                    'value' => $stats['winRate'] ?? null,
                    'hint' => 'Percentage of wars ending in victory',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>',
                    'gradient' => 'from-success/20 to-success/5',
                    'accent' => 'text-success',
                    'ring' => 'ring-success/20',
                    'format' => 'percent',
                ],
                [
                    'label' => 'Population',
                    'value' => $stats['totalPopulation'] ?? null,
                    'hint' => 'Total citizens across the alliance',
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/></svg>',
                    'gradient' => 'from-secondary/20 to-secondary/5',
                    'accent' => 'text-secondary',
                    'ring' => 'ring-secondary/20',
                    'format' => 'int',
                ],
            ];
        @endphp

        <section class="mt-12 sm:mt-16">
            <div class="mb-8 max-w-2xl">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-primary/80">Beyond the basics</p>
                <h2 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">What the alliance has built.</h2>
                <p class="mt-3 text-base leading-7 text-base-content/60">{{ $statsIntro }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($deepStats as $stat)
                    @php
                        $value = $stat['value'];
                        $formatted = '—';
                        if (is_numeric($value)) {
                            if ($stat['format'] === 'percent') {
                                $formatted = number_format((float) $value, 1) . '%';
                            } elseif ($stat['format'] === 'float') {
                                $formatted = number_format((float) $value, 1);
                            } else {
                                $formatted = number_format((int) $value);
                            }
                        }
                    @endphp
                    <div class="group relative overflow-hidden rounded-[1.75rem] border border-base-300/50 bg-base-100 p-6 shadow-lg transition-all duration-300 hover:-translate-y-1 hover:shadow-xl">
                        <div class="absolute inset-0 bg-gradient-to-br {{ $stat['gradient'] }} opacity-0 transition-opacity duration-300 group-hover:opacity-100"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-base-content/50">{{ $stat['label'] }}</p>
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-base-200/80 {{ $stat['accent'] }} ring-1 {{ $stat['ring'] }} transition-colors group-hover:bg-base-100">
                                    {!! $stat['icon'] !!}
                                </div>
                            </div>
                            <p class="mt-4 text-4xl font-black tracking-tight {{ $stat['accent'] }}">
                                {{ $formatted }}
                            </p>
                            <p class="mt-2 text-sm text-base-content/50">{{ $stat['hint'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         ABOUT & ONBOARDING
    ═══════════════════════════════════════════════════════════════ --}}
    <section id="about" class="mt-12 grid gap-6 lg:grid-cols-2 sm:mt-16">
        {{-- What to expect --}}
        <div class="rounded-[2rem] border border-base-300/50 bg-base-100 p-6 shadow-xl sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-secondary/80">Getting started</p>
            <h2 class="mt-2 text-3xl font-black tracking-tight">Three steps to get in.</h2>
            <p class="mt-3 text-base leading-7 text-base-content/60">No hoops to jump through. The process is built to be fast and straightforward.</p>

            <div class="mt-8 space-y-4">
                @foreach($steps as $step)
                    <div class="group flex gap-5 rounded-2xl border border-base-300/40 bg-base-200/40 p-5 transition-colors hover:bg-base-200/70">
                        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl text-lg font-black {{ $step['numClasses'] }}">
                            {{ $step['number'] }}
                        </div>
                        <div>
                            <h3 class="text-lg font-bold">{{ $step['title'] }}</h3>
                            <p class="mt-1 text-sm leading-6 text-base-content/65">{{ $step['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Programs --}}
        <div class="rounded-[2rem] border border-base-300/50 bg-gradient-to-br from-base-100 via-base-100 to-primary/5 p-6 shadow-xl sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-accent/80">What you get</p>
            <h2 class="mt-2 text-3xl font-black tracking-tight">Built for nations that want to grow.</h2>
            <p class="mt-3 text-base leading-7 text-base-content/60">Every program exists to make your time in the alliance productive and worthwhile.</p>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                @foreach($programs as $program)
                    <div class="group rounded-2xl border border-base-300/40 bg-base-100/80 p-5 shadow-sm transition-all duration-200 hover:shadow-md hover:-translate-y-0.5">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl {{ $program['iconClasses'] }}">
                            {!! $program['icon'] !!}
                        </div>
                        <h3 class="text-base font-bold">{{ $program['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-base-content/60">{{ $program['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════
         FINAL CTA
    ═══════════════════════════════════════════════════════════════ --}}
    <section class="mt-12 sm:mt-16">
        <div class="relative overflow-hidden rounded-[2.5rem] border border-base-300/40 bg-base-100 shadow-2xl">
            {{-- Background --}}
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -right-20 -top-20 h-80 w-80 rounded-full bg-primary/8 blur-3xl"></div>
                <div class="absolute -bottom-10 -left-10 h-60 w-60 rounded-full bg-secondary/8 blur-3xl"></div>
                <div class="absolute inset-0 opacity-[0.02]" style="background-image: linear-gradient(hsl(var(--bc)) 1px, transparent 1px), linear-gradient(90deg, hsl(var(--bc)) 1px, transparent 1px); background-size: 40px 40px;"></div>
            </div>

            <div class="relative px-6 py-10 sm:px-10 sm:py-14 lg:px-14">
                <div class="grid items-center gap-8 lg:grid-cols-[1.3fr_0.7fr]">
                    <div class="space-y-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-primary/80">Ready?</p>
                        <h2 class="max-w-xl text-3xl font-black tracking-tight sm:text-4xl">
                            Make your move and join {{ $allianceName }}.
                        </h2>
                        <p class="max-w-xl text-base leading-7 text-base-content/65">
                            {{ $closingText }}
                        </p>
                        <div class="flex flex-wrap items-center gap-4 pt-2">
                            <a href="{{ route('apply.show') }}" class="btn btn-primary btn-lg gap-2 shadow-lg shadow-primary/25 transition-all duration-200 hover:shadow-xl hover:shadow-primary/30 hover:-translate-y-0.5">
                                {{ $ctaLabel }}
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            </a>
                            @if($primaryLink)
                                <a href="{{ $primaryLink }}" class="btn btn-ghost btn-lg gap-2 text-base-content/70 hover:text-base-content" target="_blank" rel="noopener">
                                    {{ $primaryLinkLabel }}
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="rounded-2xl border border-base-300/40 bg-base-200/50 p-6">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-base-content/40">Before you apply</p>
                        <ul class="mt-4 space-y-3">
                            <li class="flex items-start gap-3 text-sm leading-6 text-base-content/65">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary/60" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Look around and get a feel for the alliance
                            </li>
                            <li class="flex items-start gap-3 text-sm leading-6 text-base-content/65">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary/60" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Reach out if you have questions first
                            </li>
                            <li class="flex items-start gap-3 text-sm leading-6 text-base-content/65">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary/60" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Applications are reviewed quickly — expect a response soon
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="h-4"></div>
@endsection
