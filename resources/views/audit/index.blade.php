@extends('layouts.main')

@section('content')
    @php
        $totalViolations = $violationsByPriority->flatten()->count();
        $priorityColors = [
            'high' => 'error',
            'medium' => 'warning',
            'low' => 'info',
            'info' => 'neutral',
        ];
    @endphp

    <div class="space-y-6">
        <div class="rounded-2xl border border-base-300 bg-gradient-to-r from-primary/10 via-base-100 to-base-100 p-6 shadow">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Audit health</p>
                    <h1 class="text-3xl font-bold leading-tight">
                        {{ $nation->leader_name }}
                        <span class="text-base-content/60 text-lg font-semibold">({{ $nation->nation_name }})</span>
                    </h1>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm">
                        <span class="badge badge-outline">Score {{ number_format($nation->score, 2) }}</span>
                        <span class="badge badge-outline">{{ $nation->num_cities }} cities</span>
                        <span class="badge badge-outline badge-primary">{{ $totalViolations }} active violation{{ $totalViolations === 1 ? '' : 's' }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-ghost">Updated {{ now()->diffForHumans() }}</span>
                    <span class="badge badge-outline">Alliance member audit</span>
                </div>
            </div>
        </div>

        @if($totalViolations === 0)
            <div class="alert alert-success shadow">
                <div>
                    <h3 class="font-semibold text-lg">All clear</h3>
                    <p class="text-sm">No active audit findings for your nation or cities. Keep it up!</p>
                </div>
            </div>
        @endif

        @foreach($priorityOrder as $priority)
            @php
                $items = $violationsByPriority->get($priority->value, collect());
                $color = $priorityColors[$priority->value] ?? 'neutral';
            @endphp

            <div class="rounded-2xl border border-base-300 bg-base-100 shadow-sm">
                <div class="flex items-center justify-between border-b border-base-200 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="badge badge-{{ $color }} badge-lg">{{ ucfirst($priority->value) }}</span>
                        <div>
                            <p class="font-semibold leading-tight">{{ $items->count() }} {{ $items->count() === 1 ? 'violation' : 'violations' }}</p>
                            <p class="text-sm text-base-content/70">
                                @if($priority->value === 'high')
                                    Needs immediate attention.
                                @elseif($priority->value === 'medium')
                                    Address soon to stay compliant.
                                @elseif($priority->value === 'low')
                                    Track and resolve when convenient.
                                @else
                                    Informational checks for awareness.
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                @if($items->isEmpty())
                    <div class="p-5 text-sm text-base-content/70">
                        No {{ $priority->value }} priority findings.
                    </div>
                @else
                    <div class="grid gap-4 p-5 md:grid-cols-2">
                        @foreach($items as $violation)
                            <div class="card bg-base-100 border border-base-300 shadow-sm">
                                <div class="card-body space-y-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 class="card-title text-lg">{{ $violation->rule?->name ?? 'Audit rule' }}</h3>
                                            <p class="text-sm text-base-content/70">{{ $violation->rule?->description ?? 'No description provided.' }}</p>
                                        </div>
                                        <span class="badge badge-outline">{{ ucfirst($violation->rule?->target_type?->value ?? 'nation') }}</span>
                                    </div>
                                    <div class="rounded-xl border border-base-300 bg-base-200/60 p-3 text-sm">
                                        @if(optional($violation->rule?->target_type)->value === 'city')
                                            <div class="font-semibold">{{ $violation->city?->name ?? 'City' }}</div>
                                            <div class="text-base-content/70">
                                                Infra {{ number_format((float) $violation->city?->infrastructure, 0) }} ·
                                                Land {{ number_format((float) $violation->city?->land, 0) }} ·
                                                {{ $violation->city?->powered ? 'Powered' : 'Unpowered' }}
                                            </div>
                                            @if($violation->nation)
                                                <div class="mt-1 text-base-content/70">
                                                    {{ $violation->nation->leader_name }} ({{ $violation->nation->nation_name }})
                                                </div>
                                            @endif
                                        @else
                                            <div class="font-semibold">{{ $nation->leader_name }} ({{ $nation->nation_name }})</div>
                                            <div class="text-base-content/70">
                                                Score {{ number_format($nation->score, 2) }} · {{ $nation->num_cities }} cities
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-xs text-base-content/70">
                                        <span class="badge badge-ghost">Since {{ $violation->first_detected_at->diffForHumans() }}</span>
                                        <span class="badge badge-ghost">Checked {{ $violation->last_evaluated_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endsection
