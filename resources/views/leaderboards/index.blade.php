@extends('layouts.main')

@section('content')
    @php
        $isDirectory = $activeBoard['slug'] === 'dashboard';
    @endphp

    <div class="nexus-stack">
        <header class="nexus-page-header sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Alliance performance</p>
                <h1 class="nexus-page-title">{{ $isDirectory ? 'Leaderboards' : $activeBoard['title'] }}</h1>
                <p class="nexus-page-summary">
                    {{ $isDirectory
                        ? 'Compare current economic and raiding performance, then open a board for its full methodology and ranking.'
                        : $activeBoard['description'] }}
                </p>
            </div>

            <div class="nexus-page-header__actions">
                @if($isDirectory)
                    <span class="nexus-status nexus-status--neutral">{{ count($liveBoards) }} live boards</span>
                @else
                    <a href="{{ route('leaderboards.index') }}" class="btn btn-ghost btn-sm">
                        <x-icon name="o-arrow-left" class="size-4" aria-hidden="true" />
                        All leaderboards
                    </a>
                @endif
            </div>
        </header>

        <nav class="nexus-panel overflow-hidden" aria-label="Leaderboard directory">
            <div class="nexus-panel__header">
                <div>
                    <h2 class="nexus-section-title">Live boards</h2>
                    <p class="nexus-body-muted mt-1">Rankings are scoped to current alliance data.</p>
                </div>
            </div>
            <div class="grid gap-px bg-base-300 sm:grid-cols-2">
                @foreach ($liveBoards as $board)
                    @php($isActiveBoard = $board['slug'] === $activeBoard['slug'])
                    <a
                        href="{{ route('leaderboards.index', ['board' => $board['slug']]) }}"
                        @if($isActiveBoard) aria-current="page" @endif
                        class="group flex min-h-20 items-center gap-4 bg-base-100 px-5 py-4 transition-colors hover:bg-base-200/70 {{ $isActiveBoard ? 'border-l-4 border-primary' : '' }}"
                    >
                        <span class="grid size-10 shrink-0 place-items-center rounded-md bg-primary/12 font-display text-lg font-bold text-primary" aria-hidden="true">
                            {{ $board['icon'] }}
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold uppercase tracking-[0.16em] text-base-content/55">{{ $board['eyebrow'] }}</span>
                            <span class="mt-1 block font-semibold text-base-content group-hover:text-primary">{{ $board['name'] }}</span>
                        </span>
                        <x-icon name="o-chevron-right" class="ml-auto size-4 shrink-0 text-base-content/40" aria-hidden="true" />
                    </a>
                @endforeach
            </div>
        </nav>

        @if($isDirectory)
            <section class="grid gap-5 lg:grid-cols-2" aria-label="Current leaderboard leaders">
                @foreach ($dashboardBoards as $board)
                    @php($champion = $board['champion'] ?? null)
                    <article class="nexus-panel overflow-hidden">
                        <div class="nexus-panel__header">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-md bg-primary/12 font-display text-lg font-bold text-primary" aria-hidden="true">
                                    {{ $board['icon'] }}
                                </span>
                                <div class="min-w-0">
                                    <p class="nexus-kicker">{{ $board['eyebrow'] }}</p>
                                    <h2 class="nexus-section-title">{{ $board['name'] }}</h2>
                                </div>
                            </div>
                            <span class="nexus-status nexus-status--success">Live</span>
                        </div>

                        <div class="nexus-panel__body grid gap-5">
                            <p class="text-sm leading-6 text-base-content/70">{{ $board['description'] }}</p>

                            @if($champion)
                                <div class="grid gap-4 border-y border-base-300 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                    <div class="min-w-0">
                                        <p class="nexus-stat-label">Current leader</p>
                                        <a href="{{ $champion['nation_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 block truncate text-xl font-bold text-primary hover:underline">
                                            {{ $champion['nation_name'] }}
                                        </a>
                                        <p class="mt-1 truncate text-sm text-base-content/60">{{ $champion['leader_name'] }}</p>
                                    </div>
                                    <div class="sm:text-right">
                                        <p class="nexus-stat-label">{{ $champion['metric_label'] }}</p>
                                        <p class="mt-2 font-display text-2xl font-bold tabular-nums text-base-content">{{ $champion['metric_value'] }}</p>
                                    </div>
                                </div>
                            @else
                                <div class="nexus-empty-state min-h-0 py-5">
                                    <p class="font-semibold">No ranking data yet</p>
                                    <p class="text-sm text-base-content/60">This board will populate after its source data is refreshed.</p>
                                </div>
                            @endif

                            @if(! empty($board['kpis']))
                                <dl class="grid gap-px overflow-hidden rounded-md border border-base-300 bg-base-300 sm:grid-cols-3">
                                    @foreach($board['kpis'] as $kpi)
                                        <div class="bg-base-100 p-3">
                                            <dt class="nexus-stat-label">{{ $kpi['label'] }}</dt>
                                            <dd class="mt-1 break-words text-sm font-semibold tabular-nums text-base-content">{{ $kpi['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif

                            <div class="flex justify-end">
                                <a href="{{ route('leaderboards.index', ['board' => $board['slug']]) }}" class="btn btn-primary btn-sm">
                                    Open {{ $board['name'] }}
                                    <x-icon name="o-arrow-right" class="size-4" aria-hidden="true" />
                                </a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>
        @elseif(! empty($activeBoard['partial']))
            @include($activeBoard['partial'], ['activeBoard' => $activeBoard, 'activePayload' => $activePayload])
        @endif
    </div>
@endsection
