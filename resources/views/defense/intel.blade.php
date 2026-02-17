@extends('layouts.main')

@section('content')
    <div class="space-y-6">
        <div class="card bg-gradient-to-br from-secondary via-primary to-accent text-primary-content shadow-xl">
            <div class="card-body grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
                <div class="lg:col-span-2 space-y-3">
                    <p class="text-xs uppercase tracking-[0.25em] text-primary-content/70">Defense • Shared Intel</p>
                    <h1 class="text-3xl font-black sm:text-4xl">Alliance Intel Library</h1>
                    <p class="text-sm sm:text-base text-primary-content/80 max-w-3xl">
                        Drop spy reports, auto-parse the resource haul, and give everyone a searchable feed of the latest intel.
                        Filter by nation ID, check detection risk, and keep operations moving fast.
                    </p>
                </div>
                <div class="w-full">
                    <div class="rounded-2xl bg-base-100/10 border border-primary-content/20 p-4 backdrop-blur">
                        <p class="text-xs uppercase text-primary-content/70">Quick status</p>
                        <div class="mt-2 text-4xl font-black leading-none">{{ number_format($reports->total()) }}</div>
                        <p class="text-sm text-primary-content/70">intel reports on file</p>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs">
                            <span class="badge badge-ghost badge-sm bg-base-100/20 border-primary-content/20">
                                Latest: {{ optional($reports->first())->created_at?->diffForHumans() ?? '—' }}
                            </span>
                            @if($selectedNation)
                                <span class="badge badge-outline badge-sm">
                                    Focus: {{ $selectedNation->nation_name }} (#{{ $selectedNation->id }})
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <form method="GET" action="{{ route('defense.intel') }}" class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body space-y-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Search</p>
                        <h2 class="card-title">Filter by Nation ID</h2>
                        <p class="text-sm text-base-content/70">Jump to intel tied to a specific nation. We match reports by nation name automatically.</p>
                    </div>
                    <div class="join w-full">
                        <input
                            type="number"
                            name="nation_id"
                            value="{{ $nationId }}"
                            class="input input-bordered join-item w-full"
                            placeholder="e.g. 123456"
                            min="1"
                        />
                        <button class="btn btn-primary join-item" type="submit">Search</button>
                    </div>
                    @if($selectedNation)
                        <div class="p-3 rounded-xl bg-base-200/70 border border-base-300 text-sm">
                            <p class="font-semibold">{{ $selectedNation->nation_name }}</p>
                            <p class="text-base-content/70">Leader: {{ $selectedNation->leader_name }}</p>
                            <p class="text-base-content/70">Alliance: {{ $selectedNation->alliance_id ?: 'None' }}</p>
                        </div>
                    @endif
                    @if($nationId && ! $selectedNation)
                        <p class="text-sm text-error">No nation found for ID {{ $nationId }}.</p>
                    @endif
                </div>
            </form>

            <form method="POST" action="{{ route('defense.intel.store') }}" class="card bg-base-100 border border-base-300 shadow-sm lg:col-span-2">
                @csrf
                <div class="card-body space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Submit</p>
                            <h2 class="card-title">Drop a new intel report</h2>
                        </div>
                        <div class="badge badge-outline">{{ data_get(Auth::user(), 'nation.leader_name', 'You') }}</div>
                    </div>
                    <textarea
                        name="report"
                        rows="5"
                        class="textarea textarea-bordered w-full"
                        placeholder="Paste the full intel result text here"
                    >{{ old('report') }}</textarea>
                    @error('report')
                        <p class="text-sm text-error">{{ $message }}</p>
                    @enderror
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm text-base-content/70">
                            We’ll parse the nation name, cash, and resources automatically. Keep the original text intact.
                        </div>
                        <button class="btn btn-primary" type="submit">Save intel</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Reports</p>
                        <h2 class="card-title">Latest intel drops</h2>
                    </div>
                    <div class="text-sm text-base-content/70">{{ $reports->firstItem() ?? 0 }}-{{ $reports->lastItem() ?? 0 }} of {{ $reports->total() }}</div>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse($reports as $report)
                        <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-xl font-bold">{{ $report->nation_name }}</h3>
                                        @if($report->nation_id)
                                            <span class="badge badge-outline badge-sm">#{{ $report->nation_id }}</span>
                                            <a
                                                href="https://politicsandwar.com/nation/id={{ $report->nation_id }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="link link-primary text-sm"
                                            >
                                                View nation
                                            </a>
                                        @endif
                                        <span class="badge badge-ghost badge-sm">{{ ucfirst($report->source) }}</span>
                                    </div>
                                    <p class="text-sm text-base-content/70">
                                        {{ $report->created_at->diffForHumans() }} • Submitted by {{ optional($report->user)->name ?? 'Discord Bot' }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="badge {{ $report->was_detected ? 'badge-error' : 'badge-success' }}">
                                        {{ $report->was_detected ? 'Detected' : 'Undetected' }}
                                    </span>
                                    <span class="badge badge-outline">Spies lost: {{ number_format($report->spies_captured) }}</span>
                                    <span class="badge badge-outline">Op cost: ${{ number_format($report->operation_cost, 2) }}</span>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 text-sm">
                                @foreach([
                                    'money' => 'Cash',
                                    'coal' => 'Coal',
                                    'oil' => 'Oil',
                                    'uranium' => 'Uranium',
                                    'lead' => 'Lead',
                                    'iron' => 'Iron',
                                    'bauxite' => 'Bauxite',
                                    'gasoline' => 'Gasoline',
                                    'munitions' => 'Munitions',
                                    'steel' => 'Steel',
                                    'aluminum' => 'Aluminum',
                                    'food' => 'Food',
                                ] as $field => $label)
                                    <div class="p-3 rounded-xl bg-base-100 border border-base-300">
                                        <p class="text-xs uppercase text-base-content/60">{{ $label }}</p>
                                        <p class="font-semibold">
                                            @if($field === 'money')
                                                ${{ number_format($report->$field, 2) }}
                                            @else
                                                {{ number_format($report->$field, 2) }}
                                            @endif
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                            <details class="mt-3">
                                <summary class="text-sm text-base-content/70 cursor-pointer">View raw report text</summary>
                                <pre class="mt-2 whitespace-pre-wrap text-xs bg-base-100 rounded-lg border border-base-300 p-3">{{ $report->raw_text }}</pre>
                            </details>
                        </div>
                    @empty
                        <div class="p-6 rounded-xl bg-base-200/60 border border-base-300 text-base-content/70">
                            No intel reports yet. Drop one above to get the feed started.
                        </div>
                    @endforelse
                </div>
                <div class="mt-4">
                    {{ $reports->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
