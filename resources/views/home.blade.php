@extends('layouts.main')

@section("content")
    @php
        $stats = $publicStats ?? [];
        $headline = $homeContent['headline'] ?? ($allianceName ?? config('app.name', 'Nexus AMS'));
        $tagline = $homeContent['tagline'] ?? '';
        $about = $homeContent['about'] ?? '';
        $highlights = $homeContent['highlights'] ?? [];
        $highlights = is_array($highlights) ? $highlights : [];
        $appLabel = $appName ?? config('app.name', 'Nexus AMS');

        $metaStats = [
            [
                'label' => 'Members',
                'value' => $stats['members'] ?? null,
                'hint' => 'Active nations in our roster',
                'color' => 'text-primary',
                'format' => 'int',
            ],
            [
                'label' => 'Alliance score',
                'value' => $stats['score'] ?? null,
                'hint' => 'Score refreshed from PW API',
                'color' => 'text-secondary',
                'format' => 'float',
            ],
            [
                'label' => 'Average score',
                'value' => $stats['avgScore'] ?? null,
                'hint' => 'Balancing growth across members',
                'color' => 'text-accent',
                'format' => 'float',
            ],
            [
                'label' => 'Rank',
                'value' => $stats['rank'] ?? null,
                'hint' => 'Whole-alliance standing',
                'color' => 'text-success',
                'format' => 'int',
            ],
        ];
    @endphp

    <div class="space-y-12">
        {{-- Hero --}}
        <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary/10 via-base-200 to-secondary/10 p-10">
            <div class="absolute inset-0 bg-gradient-to-b from-base-100/60 via-transparent to-base-100/60"></div>
            <div class="absolute -right-28 -top-16 h-64 w-64 rounded-full bg-primary/20 blur-3xl"></div>
            <div class="relative grid gap-10 lg:grid-cols-2 lg:items-center">
                <div class="space-y-6">
                    <div class="inline-flex items-center gap-2 rounded-full bg-base-100/80 px-4 py-2 text-sm font-semibold shadow">
                        <span class="h-2 w-2 rounded-full bg-success animate-pulse"></span>
                        Alliance recruitment
                    </div>
                    <h1 class="text-4xl font-bold leading-tight tracking-tight sm:text-5xl">
                        {{ $headline }}
                    </h1>
                    <p class="text-lg text-base-content/80">
                        {{ $tagline }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('apply.show') }}" class="btn btn-primary btn-lg shadow-lg shadow-primary/30">
                            Apply to join
                        </a>
                        @if(!empty($stats['discord_link']))
                            <a href="{{ $stats['discord_link'] }}" class="btn btn-ghost btn-lg" target="_blank" rel="noopener">
                                Join our Discord
                            </a>
                        @elseif(!empty($stats['forum_link']))
                            <a href="{{ $stats['forum_link'] }}" class="btn btn-ghost btn-lg" target="_blank" rel="noopener">
                                Visit our forum
                            </a>
                        @else
                            <a href="#programs" class="btn btn-ghost btn-lg">
                                Learn about programs
                            </a>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-6 pt-2 text-sm text-base-content/70">
                        <div class="flex items-center gap-3">
                            <div class="avatar">
                                <div class="w-12 rounded-full border border-base-300 bg-base-100">
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
                                <p class="font-semibold text-base-content">{{ $allianceName }}</p>
                                <p class="text-base-content/70">Powered by {{ $appLabel }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <div class="mockup-window border border-base-300 bg-base-100 shadow-2xl">
                        <div class="space-y-4 bg-base-200 px-8 py-6">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-base-content/70">What to expect</span>
                                <span class="badge badge-success badge-lg">Open</span>
                            </div>
                            <p class="text-base text-base-content/80">{{ $about }}</p>
                            <div class="border-t border-base-300 pt-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-base-content/60 mb-3">Highlights</p>
                                <div class="space-y-2">
                                    @forelse($highlights as $highlight)
                                        <div class="flex items-start gap-3">
                                            <span class="mt-1 h-2 w-2 rounded-full bg-primary"></span>
                                            <p class="text-sm text-base-content/80">{{ $highlight }}</p>
                                        </div>
                                    @empty
                                        <p class="text-sm text-base-content/60">Configurable highlights will appear here.</p>
                                    @endforelse
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="card bg-base-100 shadow-sm">
                                    <div class="card-body gap-2">
                                        <h3 class="card-title text-base">Admissions</h3>
                                        <p class="text-sm text-base-content/70">Structured application powered by Nexus AMS to keep reviewers fast and fair.</p>
                                    </div>
                                </div>
                                <div class="card bg-base-100 shadow-sm">
                                    <div class="card-body gap-2">
                                        <h3 class="card-title text-base">Programs</h3>
                                        <p class="text-sm text-base-content/70">Loans, grants, and mentorship programs stay transparent for recruits.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Public stats --}}
        <section class="space-y-6">
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-primary">Alliance at a glance</p>
                <h2 class="text-3xl font-bold">Transparent numbers for prospective members.</h2>
                <p class="max-w-3xl text-base text-base-content/80">
                    We share a snapshot of our public stats so you can see where we stand today. Deeper operational data stays secure for leadership.
                </p>
            </div>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
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
                    <div class="card bg-base-100 shadow-lg border border-base-300/60">
                        <div class="card-body gap-1">
                            <p class="text-sm text-base-content/60">{{ $stat['label'] }}</p>
                            <p class="text-3xl font-bold {{ $stat['color'] }}">{{ $formatted }}</p>
                            <p class="text-xs text-base-content/60">{{ $stat['hint'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Programs --}}
        <section id="programs" class="rounded-3xl bg-base-200/60 p-8 shadow-inner space-y-6">
            <div class="flex flex-col gap-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-secondary">Programs & culture</p>
                <h2 class="text-3xl font-bold">Grow with clear expectations and real support.</h2>
                <p class="max-w-3xl text-base text-base-content/80">
                    From your first city grant to wartime coordination, we keep everything organized. Nexus AMS powers the workflows, while leaders keep the experience personal.
                </p>
            </div>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <div class="card bg-base-100 shadow-xl border border-base-300/60">
                    <div class="card-body gap-3">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-primary/20 text-primary flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14v7m0 0l-3-3m3 3l3-3" />
                                </svg>
                            </div>
                            <h3 class="card-title text-lg">Onboarding</h3>
                        </div>
                        <p class="text-base-content/80">Guided application, Discord verification, and mentor pairing so you can ramp quickly.</p>
                        <ul class="space-y-2 text-sm text-base-content/70">
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Clear expectations from day one</li>
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Status visibility for applicants</li>
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Alliance wording stays configurable</li>
                        </ul>
                    </div>
                </div>
                <div class="card bg-base-100 shadow-xl border border-base-300/60">
                    <div class="card-body gap-3">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-secondary/20 text-secondary flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h10m-6-8l3-3 3 3" />
                                </svg>
                            </div>
                            <h3 class="card-title text-lg">Economy</h3>
                        </div>
                        <p class="text-base-content/80">Loans, grants, and infrastructure support tracked through Nexus AMS so nothing slips.</p>
                        <ul class="space-y-2 text-sm text-base-content/70">
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Transparent queues and approvals</li>
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Self-serve status updates</li>
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Policy-driven caps and templates</li>
                        </ul>
                    </div>
                </div>
                <div class="card bg-base-100 shadow-xl border border-base-300/60">
                    <div class="card-body gap-3">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-accent/20 text-accent flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 17l-3.586-3.586a1 1 0 010-1.414L8 8m8 9l3.586-3.586a1 1 0 000-1.414L16 8" />
                                </svg>
                            </div>
                            <h3 class="card-title text-lg">Defense</h3>
                        </div>
                        <p class="text-base-content/80">Counter finder, raid tools, and war aid workflows keep commanders coordinated without exposing sensitive intel.</p>
                        <ul class="space-y-2 text-sm text-base-content/70">
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Fast tasking with public-safe summaries</li>
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Queue-aware jobs and alerts</li>
                            <li class="flex items-center gap-2"><span class="badge badge-ghost badge-sm"></span> Built for reruns and retries</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- Toolkit --}}
        <section class="rounded-3xl border border-base-300/80 bg-base-100 p-10 shadow-2xl space-y-6">
            <div class="grid gap-6 lg:grid-cols-[2fr_1fr] lg:items-center">
                <div class="space-y-4">
                    <h3 class="text-3xl font-bold">Powered by {{ $appLabel }}</h3>
                    <p class="text-base text-base-content/80">Leadership runs day-to-day operations on {{ $appLabel }} so guests get a polished experience while members benefit from real structure.</p>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('apply.show') }}" class="btn btn-primary btn-lg">Begin application</a>
                        <a href="#programs" class="btn btn-lg btn-outline">Preview programs</a>
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm text-base-content/70">
                        <div class="flex items-center gap-2">
                            <span class="badge badge-outline badge-sm"></span>
                            Configurable wording for any alliance
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge badge-outline badge-sm"></span>
                            Public overview, secure back office
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge badge-outline badge-sm"></span>
                            PW API-aware dashboards
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl bg-base-200/80 p-6 shadow-inner">
                    <h4 class="text-lg font-semibold mb-3">Guest highlights</h4>
                    <ul class="space-y-3 text-sm text-base-content/80">
                        <li class="flex gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-primary"></span>
                            Clean welcome page with clear calls to apply or learn more.
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-secondary"></span>
                            Alliance-neutral defaults—you add your tone and policy details.
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-accent"></span>
                            Public sections stay informative without exposing sensitive tools.
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
@endsection
