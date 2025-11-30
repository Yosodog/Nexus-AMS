@extends('layouts.main')

@section('content')
    <div class="max-w-6xl mx-auto px-4 py-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="text-3xl font-bold text-primary">Spy Assignments</h1>
                <p class="text-base-content/70">Your queued espionage targets and recommended safety levels.</p>
            </div>
            <div class="badge badge-info badge-lg">Live</div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @forelse($assignments as $assignment)
                <div class="card bg-base-200 shadow-md">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm uppercase text-base-content/60">Round {{ $assignment->round?->round_number }}</div>
                                <h3 class="card-title">{{ \Illuminate\Support\Str::headline(strtolower($assignment->op_type?->name ?? '')) }}</h3>
                            </div>
                            <div class="badge badge-primary">{{ number_format($assignment->calculated_odds, 1) }}%</div>
                        </div>
                        <p class="text-sm text-base-content/70">Target: <span class="font-semibold">{{ $assignment->defender?->leader_name }}</span> ({{ $assignment->defender?->nation_name }})</p>
                        <div class="flex flex-wrap gap-2">
                            <div class="badge badge-outline">Safety {{ $assignment->safety_level }}</div>
                            <div class="badge badge-outline badge-secondary">Impact {{ number_format($assignment->expected_impact, 1) }}</div>
                            <div class="badge badge-outline badge-accent">Synergy {{ number_format($assignment->policy_synergy, 2) }}</div>
                            @if($assignment->low_odds_flag)
                                <div class="badge badge-error">Low odds</div>
                            @endif
                        </div>
                        <div class="card-actions justify-between items-center mt-3">
                            <div class="text-xs text-base-content/60">
                                Campaign: {{ $assignment->round?->campaign?->name ?? 'n/a' }}
                            </div>
                            <a class="btn btn-sm btn-primary"
                               href="https://politicsandwar.com/nation/espionage/eid={{ $assignment->defender?->id }}"
                               target="_blank" rel="noopener">
                                Open espionage
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-2">
                    <div class="alert alert-info">
                        <div>
                            <span>No spy assignments yet. Check back after leadership publishes orders.</span>
                        </div>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
@endsection
