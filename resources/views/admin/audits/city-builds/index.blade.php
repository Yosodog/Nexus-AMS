@extends('layouts.admin')

@section('title', 'Member City Build Audit')

@section('content')
    <x-header title="Member City Build Audit" separator use-h1>
        <x-slot:subtitle>Compare every active member city with its current optimized build, refresh recommendations, and copy a ready-to-send build message.</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.audits.index') }}" class="btn btn-outline">Audit overview</a>
                @can('manage-audits')
                    <form method="POST" action="{{ route('admin.audits.city-builds.recommendations.regenerate-all') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="o-arrow-path" class="size-5" aria-hidden="true" />
                            Refresh all builds
                        </button>
                    </form>
                @endcan
            </div>
        </x-slot:actions>
    </x-header>

    <section class="nexus-metrics" aria-label="City build audit summary for this page">
        <div class="nexus-metric">
            <span class="nexus-stat-label">Members shown</span>
            <strong class="nexus-stat-value">{{ number_format($summary['members']) }}</strong>
            <span class="nexus-stat-description">On this page</span>
        </div>
        <div class="nexus-metric">
            <span class="nexus-stat-label">Cities reviewed</span>
            <strong class="nexus-stat-value">{{ number_format($summary['cities']) }}</strong>
            <span class="nexus-stat-description">Against current recommendations</span>
        </div>
        <div class="nexus-metric bg-success/5">
            <span class="nexus-stat-label">Matching cities</span>
            <strong class="nexus-stat-value text-success">{{ number_format($summary['matching_cities']) }}</strong>
            <span class="nexus-stat-description">Exact build, sufficient infra and land</span>
        </div>
        <div class="nexus-metric bg-warning/5">
            <span class="nexus-stat-label">Need changes</span>
            <strong class="nexus-stat-value text-warning">{{ number_format($summary['cities_needing_changes']) }}</strong>
            <span class="nexus-stat-description">Cities on this page</span>
        </div>
        <div class="nexus-metric bg-warning/5">
            <span class="nexus-stat-label">Mixed builds</span>
            <strong class="nexus-stat-value text-warning">{{ number_format($summary['members_with_mixed_builds']) }}</strong>
            <span class="nexus-stat-description">Members whose cities differ</span>
        </div>
        <div class="nexus-metric bg-error/5">
            <span class="nexus-stat-label">Builds unavailable</span>
            <strong class="nexus-stat-value text-error">{{ number_format($summary['missing_recommendations']) }}</strong>
            <span class="nexus-stat-description">Missing or outdated recommendations</span>
        </div>
    </section>

    <section class="nexus-panel" aria-labelledby="member-builds-heading">
        <div class="nexus-panel__header">
            <div>
                <h2 id="member-builds-heading" class="nexus-section-title">Member builds</h2>
                <p class="text-sm nexus-text-muted">A city matches only when its improvements match the recommendation, it is powered, and it meets the target infrastructure and land.</p>
            </div>
            <form method="GET" action="{{ route('admin.audits.city-builds.index') }}" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                <label class="input w-full sm:w-80">
                    <x-icon name="o-magnifying-glass" class="size-5 opacity-50" aria-hidden="true" />
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Nation, leader, or nation ID"
                        aria-label="Search members"
                    />
                </label>
                <button type="submit" class="btn btn-outline">Search</button>
                @if($search !== '')
                    <a href="{{ route('admin.audits.city-builds.index') }}" class="btn btn-ghost">Clear</a>
                @endif
            </form>
        </div>

        <div class="nexus-table-shell rounded-none border-x-0 border-t-0">
            <table class="nexus-table">
                <thead>
                <tr>
                    <th scope="col">Member</th>
                    <th scope="col">Recommendation</th>
                    <th scope="col">City audit</th>
                    <th scope="col">Last calculated</th>
                    <th scope="col" class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($members as $member)
                    @php
                        $audit = $audits->get($member->id);
                        $recommendation = $member->buildRecommendation;
                        $statusClass = match ($audit['status']) {
                            'compliant' => 'badge-success',
                            'needs_changes' => 'badge-warning',
                            'outdated' => 'badge-info',
                            default => 'badge-error',
                        };
                        $statusLabel = match ($audit['status']) {
                            'compliant' => 'Matches',
                            'needs_changes' => 'Needs changes',
                            'outdated' => 'Outdated build',
                            default => 'No build',
                        };
                        $shareText = $audit['recommendation_json']
                            ? "Recommended city build for {$member->nation_name} ({$member->leader_name}).\n"
                                ."Target: ".number_format($recommendation->infra_needed)." infrastructure and ".number_format($recommendation->land_used, 2)." land.\n"
                                ."Import at https://politicsandwar.com/city/improvements/bulk-import/\n\n"
                                .$audit['recommendation_json']
                            : null;
                    @endphp
                    <tr>
                        <td>
                            <div class="grid gap-1">
                                <a
                                    href="https://politicsandwar.com/nation/id={{ $member->id }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="link link-primary font-semibold"
                                >
                                    {{ $member->nation_name }}
                                </a>
                                <span class="text-sm nexus-text-muted">{{ $member->leader_name }} · #{{ $member->id }} · C{{ $member->num_cities }}</span>
                            </div>
                        </td>
                        <td>
                            @if($audit['recommendation_status'] === 'ready')
                                <div class="grid gap-1">
                                    <span class="badge badge-success badge-soft">Current model</span>
                                    <span class="text-sm nexus-text-muted">${{ number_format($recommendation->converted_profit_per_day, 2) }} per city/day</span>
                                </div>
                            @elseif($audit['recommendation_status'] === 'outdated')
                                <span class="badge badge-info badge-soft">Refresh required</span>
                            @else
                                <span class="badge badge-error badge-soft">Not generated</span>
                            @endif
                        </td>
                        <td>
                            <div class="grid gap-1">
                                <span class="badge {{ $statusClass }} badge-soft">{{ $statusLabel }}</span>
                                @if($audit['recommendation_status'] === 'ready')
                                    <span class="text-sm nexus-text-muted">
                                        {{ $audit['matching_city_count'] }} of {{ $audit['city_count'] }} meeting the recommendation
                                    </span>
                                @else
                                    <span class="text-sm nexus-text-muted">{{ $audit['city_count'] }} tracked {{ \Illuminate\Support\Str::plural('city', $audit['city_count']) }}</span>
                                @endif
                                @if($audit['has_different_city_builds'])
                                    <span class="badge badge-warning badge-soft">
                                        {{ $audit['different_city_build_count'] }} {{ $audit['different_city_build_count'] === 1 ? 'city differs' : 'cities differ' }} from first
                                    </span>
                                @elseif($audit['city_count'] > 1)
                                    <span class="badge badge-success badge-soft">All cities use the first-city build</span>
                                @endif
                            </div>
                        </td>
                        <td data-order="{{ $recommendation?->calculated_at?->timestamp ?? 0 }}">
                            {{ $recommendation?->calculated_at?->diffForHumans() ?? 'Never' }}
                        </td>
                        <td>
                            <div class="flex flex-wrap justify-end gap-2">
                                @if($shareText)
                                    <button type="button" class="btn btn-sm btn-outline" data-copy-text="{{ $shareText }}">Copy message</button>
                                    <button type="button" class="btn btn-sm btn-outline" data-copy-text="{{ $audit['recommendation_json'] }}">Copy JSON</button>
                                @endif
                                @can('manage-audits')
                                    <form method="POST" action="{{ route('admin.audits.city-builds.recommendations.regenerate', $member) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            {{ $recommendation ? 'Regenerate' : 'Generate' }}
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="5" class="bg-base-200/40">
                            <details>
                                <summary class="cursor-pointer font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                                    Review first city
                                </summary>

                                @if($audit['recommendation_status'] === 'ready')
                                    <div class="grid gap-5 pt-4">
                                        <div class="grid gap-2">
                                            <h3 class="font-semibold">Recommended improvements</h3>
                                            <div class="flex flex-wrap gap-2">
                                                @forelse(collect($audit['expected_build'])->filter() as $field => $count)
                                                    <span class="badge badge-outline">{{ \Illuminate\Support\Str::headline($field) }} × {{ $count }}</span>
                                                @empty
                                                    <span class="text-sm nexus-text-muted">No improvements were recommended.</span>
                                                @endforelse
                                            </div>
                                        </div>

                                        <div class="grid gap-3">
                                            @if($audit['first_city'])
                                                @php($cityAudit = $audit['first_city'])
                                                <article class="grid gap-3 rounded-box border border-base-300 bg-base-100 p-4">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <div>
                                                            <a
                                                                href="https://politicsandwar.com/city/id={{ $cityAudit['id'] }}"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="link link-hover font-semibold"
                                                            >
                                                                {{ $cityAudit['name'] }}
                                                            </a>
                                                            <p class="text-sm nexus-text-muted">
                                                                {{ number_format($cityAudit['infrastructure'], 2) }} infra · {{ number_format($cityAudit['land'], 2) }} land
                                                            </p>
                                                        </div>
                                                        <span class="badge {{ $cityAudit['matches'] ? 'badge-success' : 'badge-warning' }} badge-soft">
                                                            {{ $cityAudit['matches'] ? 'Matches' : $cityAudit['change_count'].' changes' }}
                                                        </span>
                                                    </div>

                                                    @if(! $cityAudit['matches'])
                                                        <div class="flex flex-wrap gap-2 text-sm">
                                                            @if(! $cityAudit['powered'])
                                                                <span class="badge badge-error badge-soft">City is unpowered</span>
                                                            @endif
                                                            @if($cityAudit['infrastructure_shortfall'] >= 0.01)
                                                                <span class="badge badge-warning badge-soft">+{{ number_format($cityAudit['infrastructure_shortfall'], 2) }} infra needed</span>
                                                            @endif
                                                            @if($cityAudit['land_shortfall'] >= 0.01)
                                                                <span class="badge badge-warning badge-soft">+{{ number_format($cityAudit['land_shortfall'], 2) }} land needed</span>
                                                            @endif
                                                            @foreach($cityAudit['differences'] as $difference)
                                                                <span class="badge badge-outline">
                                                                    {{ $difference['label'] }}: {{ $difference['actual'] }} → {{ $difference['recommended'] }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </article>
                                            @else
                                                <p class="text-sm nexus-text-muted">No city records are available for this member.</p>
                                            @endif
                                        </div>

                                        <details class="grid gap-3 rounded-box border border-base-300 bg-base-100 p-4">
                                            <summary class="cursor-pointer font-semibold">View recommendation JSON</summary>
                                            <textarea class="textarea h-72 w-full font-mono text-xs" readonly aria-label="Recommended build JSON for {{ $member->nation_name }}">{{ $audit['recommendation_json'] }}</textarea>
                                        </details>
                                    </div>
                                @else
                                    <p class="pt-3 text-sm nexus-text-muted">Generate a current recommendation before comparing this member's first city.</p>
                                @endif
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-10 text-center nexus-text-muted">No active members matched this search.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="nexus-panel__footer flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm nexus-text-muted">
                Showing {{ number_format($members->firstItem() ?? 0) }}–{{ number_format($members->lastItem() ?? 0) }} of {{ number_format($members->total()) }} active members.
            </p>
            {{ $members->onEachSide(1)->links() }}
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-copy-text]').forEach((button) => {
            button.addEventListener('click', async () => {
                const payload = button.getAttribute('data-copy-text') || '';
                const originalText = button.textContent;

                try {
                    if (navigator.clipboard?.writeText && window.isSecureContext) {
                        await navigator.clipboard.writeText(payload);
                    } else {
                        const textarea = document.createElement('textarea');
                        textarea.value = payload;
                        textarea.setAttribute('readonly', '');
                        textarea.style.position = 'absolute';
                        textarea.style.left = '-9999px';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        textarea.remove();
                    }

                    button.textContent = 'Copied';
                } catch (error) {
                    console.error('Could not copy build recommendation', error);
                    button.textContent = 'Copy failed';
                }

                window.setTimeout(() => {
                    button.textContent = originalText;
                }, 1500);
            });
        });
    </script>
@endpush
