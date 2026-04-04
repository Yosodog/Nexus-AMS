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
        $groupLabels = [
            'power' => 'Power',
            'raw_resource' => 'Raw Resource',
            'manufacturing' => 'Manufacturing',
            'commerce_support' => 'Commerce & Support',
            'military' => 'Military',
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

        <div class="rounded-2xl border border-base-300 bg-base-100 shadow-sm">
            <div class="flex flex-col gap-4 border-b border-base-200 px-5 py-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-base-content/60">Recommended build</p>
                    <h2 class="text-2xl font-bold leading-tight">Alliance build recommendation</h2>
                    <p class="text-sm text-base-content/70">
                        One duplicate-across-all-cities build, optimized against the current profitability model and your nation's MMR floor.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('audit.recommendation.regenerate') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-primary btn-sm">Regenerate Build</button>
                    </form>

                    @if($buildRecommendation)
                        <button
                            type="button"
                            class="btn btn-outline btn-sm"
                            data-copy-build="{{ $buildRecommendationJson }}"
                        >
                            Copy JSON
                        </button>
                        <a
                            href="https://politicsandwar.com/city/improvements/bulk-import/"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn btn-primary btn-sm"
                        >
                            Open Bulk Import
                        </a>
                    @endif
                </div>
            </div>

            @if($buildRecommendation)
                <div class="space-y-5 p-5">
                    <div class="grid gap-3 md:grid-cols-4 xl:grid-cols-7">
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Profit / Day</p>
                            <p class="mt-2 text-2xl font-black {{ $buildRecommendation->converted_profit_per_day >= 0 ? 'text-success' : 'text-error' }}">
                                ${{ number_format($buildRecommendation->converted_profit_per_day, 2) }}
                            </p>
                            <p class="mt-1 text-xs text-base-content/60">Per city recommendation</p>
                        </div>
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Money / Day</p>
                            <p class="mt-2 text-2xl font-black {{ $buildRecommendation->money_profit_per_day >= 0 ? 'text-success' : 'text-error' }}">
                                ${{ number_format($buildRecommendation->money_profit_per_day, 2) }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Disease</p>
                            <p class="mt-2 text-2xl font-black">{{ number_format($buildRecommendation->disease, 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Pollution</p>
                            <p class="mt-2 text-2xl font-black">{{ number_format($buildRecommendation->pollution) }}</p>
                        </div>
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Crime</p>
                            <p class="mt-2 text-2xl font-black">{{ number_format($buildRecommendation->crime, 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Commerce</p>
                            <p class="mt-2 text-2xl font-black">{{ number_format($buildRecommendation->commerce) }}</p>
                        </div>
                        <div class="rounded-xl border border-base-300 bg-base-200/60 p-4">
                            <p class="text-xs uppercase tracking-wide text-base-content/60">Population</p>
                            <p class="mt-2 text-2xl font-black">{{ number_format($buildRecommendation->population) }}</p>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
                        <div class="space-y-4">
                            <div class="rounded-xl border border-base-300 bg-base-200/50 p-4">
                                <div class="flex flex-wrap gap-2 text-sm">
                                    <span class="badge badge-outline">Infra {{ number_format($buildRecommendation->infra_needed) }}</span>
                                    <span class="badge badge-outline">Land {{ number_format($buildRecommendation->land_used, 2) }}</span>
                                    <span class="badge badge-outline">{{ $buildRecommendation->imp_total }} improvements</span>
                                    <span class="badge badge-ghost">Updated {{ $buildRecommendation->calculated_at?->diffForHumans() }}</span>
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                @foreach($buildRecommendationGroups as $group => $items)
                                    <div class="rounded-xl border border-base-300 bg-base-200/40 p-4">
                                        <h3 class="font-semibold">{{ $groupLabels[$group] ?? ucfirst(str_replace('_', ' ', $group)) }}</h3>
                                        @if(empty($items))
                                            <p class="mt-2 text-sm text-base-content/60">None</p>
                                        @else
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                @foreach($items as $item)
                                                    <span class="badge badge-outline">{{ $item['label'] }} x{{ $item['count'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-xl border border-base-300 bg-base-200/40 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold">Build JSON</h3>
                                    <p class="text-sm text-base-content/60">Ready for Politics & War bulk import.</p>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-outline btn-xs"
                                    data-copy-build="{{ $buildRecommendationJson }}"
                                >
                                    Copy JSON
                                </button>
                            </div>
                            <textarea
                                class="textarea textarea-bordered mt-3 h-80 w-full font-mono text-xs"
                                readonly
                            >{{ $buildRecommendationJson }}</textarea>
                        </div>
                    </div>
                </div>
            @else
                <div class="p-5">
                    <div class="rounded-xl border border-dashed border-base-300 bg-base-200/40 p-5">
                        <h3 class="font-semibold text-lg">No build recommendation yet</h3>
                        <p class="mt-2 text-sm text-base-content/70">
                            Generate one to see the recommended JSON, profitability, city stats, and quick import actions.
                        </p>
                    </div>
                </div>
            @endif
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

    <script>
        document.querySelectorAll('[data-copy-build]').forEach((button) => {
            button.addEventListener('click', async () => {
                const payload = button.getAttribute('data-copy-build') || '';
                const originalText = button.textContent;

                try {
                    if (navigator.clipboard?.writeText && window.isSecureContext) {
                        await navigator.clipboard.writeText(payload);
                    } else {
                        const input = document.createElement('textarea');
                        input.value = payload;
                        input.setAttribute('readonly', '');
                        input.style.position = 'absolute';
                        input.style.left = '-9999px';
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                    }

                    button.textContent = 'Copied';
                } catch (error) {
                    console.error('Could not copy build JSON', error);
                    button.textContent = 'Copy failed';
                }

                window.setTimeout(() => {
                    button.textContent = originalText;
                }, 1500);
            });
        });
    </script>
@endsection
