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

        $metaStats = [
            [
                'label' => 'Members',
                'value' => $stats['members'] ?? null,
                'hint' => 'Active nations building with us',
                'color' => 'text-primary',
                'format' => 'int',
            ],
            [
                'label' => 'Alliance score',
                'value' => $stats['score'] ?? null,
                'hint' => 'A strong snapshot of alliance momentum',
                'color' => 'text-secondary',
                'format' => 'float',
            ],
            [
                'label' => 'Average score',
                'value' => $stats['avgScore'] ?? null,
                'hint' => 'Growth stays healthy across the roster',
                'color' => 'text-accent',
                'format' => 'float',
            ],
            [
                'label' => 'Rank',
                'value' => $stats['rank'] ?? null,
                'hint' => 'Where we stand on the global board',
                'color' => 'text-success',
                'format' => 'int',
            ],
        ];

        $primaryLink = null;
        $primaryLinkLabel = null;

        if (!empty($stats['discord_link'])) {
            $primaryLink = $stats['discord_link'];
            $primaryLinkLabel = 'Meet us on Discord';
        } elseif (!empty($stats['forum_link'])) {
            $primaryLink = $stats['forum_link'];
            $primaryLinkLabel = 'Visit our forum';
        } elseif (!empty($stats['wiki_link'])) {
            $primaryLink = $stats['wiki_link'];
            $primaryLinkLabel = 'Read our guide';
        }

        $memberExperience = [
            [
                'title' => 'Good onboarding',
                'description' => 'New members can get settled without a confusing first week.',
                'accent' => 'bg-primary/15 text-primary',
            ],
            [
                'title' => 'Steady support',
                'description' => 'Growth, coordination, and leadership all feel consistent once you are in.',
                'accent' => 'bg-secondary/15 text-secondary',
            ],
            [
                'title' => 'Active community',
                'description' => 'The alliance feels like a real group of people building together, not a dead roster.',
                'accent' => 'bg-accent/15 text-accent',
            ],
        ];

        $firstWeek = [
            'Apply with a clear, friendly form and get routed quickly.',
            'Meet the community, get oriented, and learn how the alliance operates.',
            'Step into grants, war support, and growth programs without guessing what comes next.',
        ];
    @endphp

    <div class="space-y-10 sm:space-y-14">
        <section class="relative overflow-hidden rounded-[2rem] border border-base-300/60 bg-base-100 px-5 py-6 shadow-2xl sm:px-8 sm:py-8 lg:px-10 lg:py-10">
            <div
                class="absolute inset-0"
                style="background:
                    radial-gradient(circle at top left, rgba(59,130,246,0.16), transparent 30%),
                    radial-gradient(circle at 80% 20%, rgba(16,185,129,0.18), transparent 24%),
                    linear-gradient(135deg, hsl(var(--b1) / 0.94), hsl(var(--b2) / 0.82));"
            ></div>
            <div class="absolute -left-10 top-10 h-28 w-28 rounded-full border border-primary/20 bg-primary/10 blur-2xl"></div>
            <div class="absolute -right-12 bottom-8 h-40 w-40 rounded-full border border-secondary/20 bg-secondary/10 blur-3xl"></div>

            <div class="relative grid gap-6 lg:grid-cols-[1.15fr_0.85fr] lg:items-center">
                <div class="space-y-5">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="inline-flex items-center gap-2 rounded-full bg-base-100/90 px-4 py-2 font-semibold text-base-content shadow">
                            <span class="h-2.5 w-2.5 rounded-full bg-success"></span>
                            Applications open
                        </span>
                        <span class="inline-flex items-center rounded-full border border-base-300/70 bg-base-100/70 px-4 py-2 text-base-content/70">
                            Built for ambitious nations
                        </span>
                    </div>

                    <div class="space-y-3">
                        <h1 class="max-w-4xl text-3xl font-black leading-[1.08] tracking-tight sm:text-4xl lg:text-5xl">
                            {{ $headline }}
                        </h1>
                        <p class="max-w-3xl text-base leading-7 text-base-content/80 sm:text-lg">
                            {{ $tagline }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('apply.show') }}" class="btn btn-primary btn-lg shadow-lg shadow-primary/20">
                            Start your application
                        </a>
                        @if($primaryLink)
                            <a href="{{ $primaryLink }}" class="btn btn-outline btn-lg bg-base-100/80" target="_blank" rel="noopener">
                                {{ $primaryLinkLabel }}
                            </a>
                        @else
                            <a href="#why-join" class="btn btn-outline btn-lg bg-base-100/80">
                                See what makes us different
                            </a>
                        @endif
                    </div>

                    <div class="grid gap-3 pt-1 sm:grid-cols-3">
                        @foreach($memberExperience as $item)
                            <div class="rounded-2xl border border-base-300/60 bg-base-100/85 p-4 shadow-sm backdrop-blur">
                                <div class="mb-3 inline-flex rounded-xl px-3 py-2 text-sm font-semibold {{ $item['accent'] }}">
                                    {{ $item['title'] }}
                                </div>
                                <p class="text-sm leading-6 text-base-content/75">{{ $item['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-4">
                    <div class="rounded-[1.75rem] border border-base-300/60 bg-base-100/90 p-5 shadow-xl backdrop-blur">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="avatar">
                                    <div class="w-14 rounded-2xl border border-base-300 bg-base-200">
                                        @if(!empty($stats['flag']))
                                            <img src="{{ $stats['flag'] }}" alt="Alliance flag">
                                        @else
                                            <div class="flex h-full w-full items-center justify-center text-lg font-bold">
                                                {{ \Illuminate\Support\Str::of($allianceName ?? $appLabel)->substr(0, 2)->upper() }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm uppercase tracking-[0.22em] text-base-content/50">About the alliance</p>
                                    <h2 class="text-2xl font-bold">{{ $allianceName }}</h2>
                                </div>
                            </div>
                            <span class="badge badge-success badge-lg">Recruiting</span>
                        </div>

                        <p class="mt-5 text-base leading-7 text-base-content/80">
                            {{ $about }}
                        </p>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl bg-primary/10 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary/80">What matters</p>
                                <p class="mt-2 text-sm leading-6 text-base-content/75">A stable group, clear expectations, and enough structure to help members move forward.</p>
                            </div>
                            <div class="rounded-2xl bg-secondary/10 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-secondary/80">For recruits</p>
                                <p class="mt-2 text-sm leading-6 text-base-content/75">A clear sense of who the alliance is before they decide to apply.</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-[1.75rem] border border-base-300/60 bg-base-200/80 p-6 shadow-lg">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-bold">Why people join</h3>
                        </div>
                        <div class="mt-4 space-y-3">
                            @forelse($highlights as $highlight)
                                <div class="flex items-start gap-3 rounded-2xl bg-base-100/80 px-4 py-3 shadow-sm">
                                    <span class="mt-1 h-2.5 w-2.5 rounded-full bg-primary"></span>
                                    <p class="text-sm leading-6 text-base-content/80">{{ $highlight }}</p>
                                </div>
                            @empty
                                <div class="rounded-2xl bg-base-100/80 px-4 py-3 shadow-sm">
                                    <p class="text-sm leading-6 text-base-content/60">Add a few short highlights here to introduce the alliance.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl space-y-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.24em] text-primary">Alliance at a glance</p>
                    <h2 class="text-3xl font-black tracking-tight sm:text-4xl">A look at where the alliance stands.</h2>
                    <p class="text-base leading-7 text-base-content/80">
                        {{ $statsIntro }}
                    </p>
                </div>
                <div class="rounded-2xl border border-base-300/60 bg-base-100 px-4 py-3 text-sm text-base-content/70 shadow-sm">
                    Public alliance snapshot
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
                    <div class="group rounded-[1.5rem] border border-base-300/60 bg-base-100 p-6 shadow-lg transition-transform duration-200 hover:-translate-y-1 hover:shadow-xl">
                        <p class="text-sm font-medium text-base-content/55">{{ $stat['label'] }}</p>
                        <p class="mt-4 text-4xl font-black tracking-tight {{ $stat['color'] }}">{{ $formatted }}</p>
                        <p class="mt-3 text-sm leading-6 text-base-content/70">{{ $stat['hint'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="why-join" class="grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
            <div class="rounded-[2rem] border border-base-300/60 bg-base-100 p-6 shadow-xl sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.24em] text-secondary">What joining feels like</p>
                <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">What new members can expect.</h2>
                <div class="mt-6 space-y-4">
                    @foreach($firstWeek as $index => $step)
                        <div class="flex gap-4 rounded-2xl bg-base-200/80 p-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-base-100 text-base font-bold shadow-sm">
                                {{ $index + 1 }}
                            </div>
                            <p class="text-sm leading-7 text-base-content/80">{{ $step }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div id="programs" class="rounded-[2rem] bg-gradient-to-br from-base-200 via-base-100 to-primary/10 p-6 shadow-xl sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.24em] text-accent">Alliance life</p>
                <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">The day-to-day should feel easy to follow.</h2>
                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    <div class="rounded-2xl border border-base-300/60 bg-base-100/85 p-5 shadow-sm">
                        <h3 class="text-lg font-bold">Recruitment</h3>
                        <p class="mt-3 text-sm leading-6 text-base-content/75">Clear applications, quick responses, and a smoother way into the alliance.</p>
                    </div>
                    <div class="rounded-2xl border border-base-300/60 bg-base-100/85 p-5 shadow-sm">
                        <h3 class="text-lg font-bold">Growth</h3>
                        <p class="mt-3 text-sm leading-6 text-base-content/75">Support, programs, and enough structure to keep members progressing.</p>
                    </div>
                    <div class="rounded-2xl border border-base-300/60 bg-base-100/85 p-5 shadow-sm">
                        <h3 class="text-lg font-bold">Coordination</h3>
                        <p class="mt-3 text-sm leading-6 text-base-content/75">Less confusion, better communication, and a group that stays organized when it counts.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-[2rem] border border-base-300/60 bg-base-100 px-6 py-8 shadow-2xl sm:px-8 sm:py-10">
            <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
                <div class="space-y-4">
                    <p class="text-sm font-semibold uppercase tracking-[0.24em] text-primary">Ready to join?</p>
                    <h2 class="text-3xl font-black tracking-tight sm:text-4xl">Make your move and join {{ $allianceName }}.</h2>
                    <p class="max-w-3xl text-base leading-7 text-base-content/80">
                        {{ $closingText }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('apply.show') }}" class="btn btn-primary btn-lg">Apply now</a>
                        @if($primaryLink)
                            <a href="{{ $primaryLink }}" class="btn btn-outline btn-lg" target="_blank" rel="noopener">
                                {{ $primaryLinkLabel }}
                            </a>
                        @endif
                    </div>
                </div>
                <div class="rounded-[1.5rem] bg-base-200/80 p-5 shadow-inner">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-base-content/50">Before you apply</p>
                    <div class="mt-4 space-y-3 text-sm leading-7 text-base-content/75">
                        <p>Take a look around, get a feel for the alliance, and reach out if you want to know more.</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
