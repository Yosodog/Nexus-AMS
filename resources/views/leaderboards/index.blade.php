@extends('layouts.main')

@section('content')
    @php
        $accentMap = [
            'emerald' => [
                'ring' => 'ring-emerald-200',
                'panel' => 'from-emerald-50 via-white to-emerald-100/70',
                'badge' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                'icon' => 'bg-emerald-600 text-white',
                'button' => 'btn-primary',
                'text' => 'text-emerald-700',
            ],
            'amber' => [
                'ring' => 'ring-amber-200',
                'panel' => 'from-amber-50 via-white to-amber-100/70',
                'badge' => 'border-amber-200 bg-amber-50 text-amber-800',
                'icon' => 'bg-amber-500 text-slate-950',
                'button' => 'btn-warning',
                'text' => 'text-amber-700',
            ],
            'sky' => [
                'ring' => 'ring-sky-200',
                'panel' => 'from-sky-50 via-white to-sky-100/70',
                'badge' => 'border-sky-200 bg-sky-50 text-sky-800',
                'icon' => 'bg-sky-600 text-white',
                'button' => 'btn-info',
                'text' => 'text-sky-700',
            ],
            'rose' => [
                'ring' => 'ring-rose-200',
                'panel' => 'from-rose-50 via-white to-rose-100/70',
                'badge' => 'border-rose-200 bg-rose-50 text-rose-800',
                'icon' => 'bg-rose-600 text-white',
                'button' => 'btn-error',
                'text' => 'text-rose-700',
            ],
            'violet' => [
                'ring' => 'ring-violet-200',
                'panel' => 'from-violet-50 via-white to-violet-100/70',
                'badge' => 'border-violet-200 bg-violet-50 text-violet-800',
                'icon' => 'bg-violet-600 text-white',
                'button' => 'btn-primary',
                'text' => 'text-violet-700',
            ],
            'orange' => [
                'ring' => 'ring-orange-200',
                'panel' => 'from-orange-50 via-white to-orange-100/70',
                'badge' => 'border-orange-200 bg-orange-50 text-orange-800',
                'icon' => 'bg-orange-500 text-slate-950',
                'button' => 'btn-warning',
                'text' => 'text-orange-700',
            ],
        ];
    @endphp

    <div class="mx-auto max-w-7xl space-y-8">
        <section class="relative overflow-hidden rounded-[2rem] border border-base-300 bg-[radial-gradient(circle_at_top_left,_rgba(16,185,129,0.16),_transparent_26%),radial-gradient(circle_at_top_right,_rgba(245,158,11,0.14),_transparent_22%),linear-gradient(135deg,rgba(255,255,255,0.99),rgba(248,250,252,0.97),rgba(255,247,237,0.94))] shadow-2xl">
            <div class="absolute inset-y-0 right-0 hidden w-1/3 bg-[linear-gradient(180deg,transparent,rgba(15,23,42,0.04),transparent)] lg:block"></div>
            <div class="relative grid gap-8 p-6 lg:grid-cols-[1.45fr,0.9fr] lg:p-8">
                <div class="space-y-5">
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.35em] text-slate-600">
                            <span>Leaderboards</span>
                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                            <span>Alliance Dashboard</span>
                        </div>
                        <h1 class="max-w-4xl text-4xl font-black tracking-tight text-slate-950 sm:text-5xl">
                            The best nations, at a glance.
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-slate-700 sm:text-base">
                            Start on the dashboard to see the current #1 nation on each live leaderboard, then drill straight into the board you want. This gives the alliance one obvious home for rankings without burying people in menus.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <span class="badge border-slate-300 bg-white/80 px-3 py-3 text-slate-700">{{ count($dashboardBoards ?? []) }} live boards</span>
                        <span class="badge border-slate-300 bg-white/80 px-3 py-3 text-slate-700">{{ count($plannedBoards ?? []) }} upcoming</span>
                        <span class="badge border-emerald-200 bg-emerald-50 px-3 py-3 text-emerald-800">Dashboard selected</span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                    <div class="rounded-[1.5rem] border border-white/70 bg-white/85 p-5 shadow-lg backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Live Boards</p>
                        <p class="mt-3 text-2xl font-black text-slate-950">{{ number_format(count($dashboardBoards ?? [])) }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-white/70 bg-white/85 p-5 shadow-lg backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Top Categories</p>
                        <p class="mt-3 text-2xl font-black text-slate-950">Economy + Raiding</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-white/70 bg-slate-950 p-5 text-white shadow-xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-300">Use It Fast</p>
                        <p class="mt-3 text-lg font-bold">Open the board that matches the question you are asking.</p>
                    </div>
                </div>
            </div>
        </section>

        @if ($activeBoard['slug'] === 'dashboard')
            <section class="space-y-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Live Leaderboards</p>
                        <h2 class="mt-1 text-3xl font-black text-base-content">Current #1 nations</h2>
                    </div>
                    <p class="max-w-2xl text-sm leading-6 text-base-content/65">
                        Each card shows the current leader for that board, the key metric they lead in, and the fastest way to open the full leaderboard page.
                    </p>
                </div>

                <div class="grid gap-5 lg:grid-cols-2">
                    @foreach ($dashboardBoards as $board)
                        @php
                            $boardAccent = $accentMap[$board['accent']] ?? $accentMap['emerald'];
                            $champion = $board['champion'] ?? null;
                        @endphp
                        <article class="group overflow-hidden rounded-[2rem] border border-base-300 bg-gradient-to-br {{ $boardAccent['panel'] }} p-6 shadow-lg transition hover:-translate-y-1 hover:shadow-2xl">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-4">
                                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-xl font-black shadow-lg {{ $boardAccent['icon'] }}">
                                        {{ $board['icon'] }}
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.26em] {{ $boardAccent['text'] }}">{{ $board['eyebrow'] }}</p>
                                        <h3 class="mt-1 text-2xl font-black text-slate-950">{{ $board['name'] }}</h3>
                                    </div>
                                </div>
                                <span class="badge {{ $boardAccent['badge'] }}">Live</span>
                            </div>

                            <p class="mt-4 text-sm leading-6 text-slate-700">{{ $board['description'] }}</p>

                            @if ($champion)
                                <div class="mt-6 rounded-[1.5rem] border border-white/80 bg-white/85 p-5 shadow-md">
                                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">#1 Right Now</p>
                                    <a href="{{ $champion['nation_url'] }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-2 text-3xl font-black text-slate-950 transition hover:text-primary">
                                        <span>{{ $champion['nation_name'] }}</span>
                                        <span class="text-sm opacity-50">-&gt;</span>
                                    </a>
                                    <p class="mt-2 text-sm text-slate-600">{{ $champion['leader_name'] }}</p>
                                    <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                                        <div>
                                            <p class="text-xs uppercase tracking-[0.22em] text-slate-500">{{ $champion['metric_label'] }}</p>
                                            <p class="mt-1 text-3xl font-black text-slate-950">{{ $champion['metric_value'] }}</p>
                                        </div>
                                        <a href="{{ route('leaderboards.index', ['board' => $board['slug']]) }}" class="btn {{ $boardAccent['button'] }}">Open Board</a>
                                    </div>
                                </div>
                            @endif

                            @if (! empty($board['kpis']))
                                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                    @foreach ($board['kpis'] as $kpi)
                                        <div class="rounded-2xl border border-white/70 bg-white/70 p-3 shadow-sm">
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ $kpi['label'] }}</p>
                                            <p class="mt-2 text-sm font-bold text-slate-950">{{ $kpi['value'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            @if (! empty($plannedBoards))
                <section class="space-y-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Coming Next</p>
                        <h2 class="mt-1 text-2xl font-black text-base-content">Planned leaderboard families</h2>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
                        @foreach ($plannedBoards as $board)
                            @php
                                $boardAccent = $accentMap[$board['accent']] ?? $accentMap['emerald'];
                            @endphp
                            <article class="rounded-[1.5rem] border border-base-300 bg-gradient-to-br {{ $boardAccent['panel'] }} p-5 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl text-lg font-black shadow-lg {{ $boardAccent['icon'] }}">
                                        {{ $board['icon'] }}
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.22em] {{ $boardAccent['text'] }}">{{ $board['eyebrow'] }}</p>
                                        <h3 class="text-lg font-black text-slate-950">{{ $board['name'] }}</h3>
                                    </div>
                                </div>
                                <p class="mt-4 text-sm leading-6 text-slate-700">{{ $board['summary'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        @else
            <section class="space-y-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Active Board</p>
                        <h2 class="mt-1 text-3xl font-black text-base-content">{{ $activeBoard['title'] }}</h2>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('leaderboards.index') }}" class="btn btn-outline">Back to Dashboard</a>
                        <span class="badge {{ ($accentMap[$activeBoard['accent']] ?? $accentMap['emerald'])['badge'] }}">{{ $activeBoard['name'] }}</span>
                    </div>
                </div>

                @if (! empty($activeBoard['partial']))
                    @include($activeBoard['partial'], ['activeBoard' => $activeBoard, 'activePayload' => $activePayload])
                @endif
            </section>
        @endif
    </div>
@endsection
