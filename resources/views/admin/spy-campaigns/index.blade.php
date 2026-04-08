@extends('layouts.admin')

@section('content')
    <div class="mb-6">
        <div class="w-full flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0 flex align-items-center gap-2">
                    Spy Campaigns
                    <span class="text-base-content/50" data-bs-toggle="tooltip" title="Coordinate espionage rounds, auto-build matchups, then message aggressors with one click.">
                        <i class="o-question-mark-circle"></i>
                    </span>
                </h3>
                <p class="text-base-content/50 mb-0">Plan espionage rounds, monitor odds, and dispatch assignments.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSpyCampaignModal">
                <i class="o-plus-circle me-1"></i> New Campaign
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-base-content/50 small">Active campaigns</div>
                        <div class="flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ $campaigns->where('status', 'active')->count() }}</span>
                            <span class="badge badge-success">ON</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-base-content/50 small">Total rounds</div>
                        <div class="flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ $campaigns->sum('rounds_count') }}</span>
                            <span class="badge badge-ghost" data-bs-toggle="tooltip" title="Rounds across all campaigns">Scope</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-base-content/50 small">Assignments queued</div>
                        <div class="flex align-items-center justify-content-between">
                            <span class="h5 mb-0">{{ $campaigns->sum('assignments_count') }}</span>
                            <span class="badge badge-info">Orders</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-none border h-100">
                    <div class="card-body py-3">
                        <div class="text-base-content/50 small">Avg latest odds</div>
                        <div class="flex align-items-center justify-content-between">
                            <span class="h5 mb-0">
                                {{ number_format($stats->avg('avg_odds'), 1) }}%
                            </span>
                            <span class="badge badge-warning" data-bs-toggle="tooltip" title="Average across the newest round of each campaign">Quality</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Alliances</th>
                            <th>Rounds</th>
                            <th>Assignments</th>
                            <th>Avg Odds</th>
                            <th>High Impact</th>
                            <th class="text-right"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($campaigns as $campaign)
                            @php($campaignStat = $stats->firstWhere('id', $campaign->id))
                            <tr>
                                <td>
                                    <div class="font-semibold">{{ $campaign->name }}</div>
                                    <div class="text-base-content/50 small">{{ \Illuminate\Support\Str::limit($campaign->description, 80) }}</div>
                                </td>
                                <td>
                                    @php($statusValue = $campaign->status instanceof \BackedEnum ? $campaign->status->value : (string) $campaign->status)
                                    <span class="badge text-bg-{{ $statusValue === 'active' ? 'success' : ($statusValue === 'draft' ? 'secondary' : 'dark') }}">
                                        {{ ucfirst($statusValue) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-primary" data-bs-toggle="tooltip" title="Allied alliances">{{ $campaign->alliances_count }}</span>
                                </td>
                                <td>{{ $campaign->rounds_count }}</td>
                                <td>{{ $campaign->assignments_count }}</td>
                                <td>{{ number_format($campaignStat['avg_odds'] ?? 0, 1) }}%</td>
                                <td>{{ number_format($campaignStat['high_impact_ratio'] ?? 0, 1) }}%</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.spy-campaigns.show', $campaign) }}" class="btn btn-sm btn-outline-primary">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4 text-base-content/50">No spy campaigns configured yet.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Create Modal --}}
    <div class="modal fade" id="createSpyCampaignModal" tabindex="-1" aria-labelledby="createSpyCampaignModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createSpyCampaignModalLabel">New Spy Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="{{ route('admin.spy-campaigns.store') }}">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="What is the goal of this spy campaign?"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label flex align-items-center gap-2">
                                Min success target (%)
                                <span class="text-base-content/50" data-bs-toggle="tooltip" title="Lowest success chance acceptable for this campaign. Assignment generation will pick the lowest safety level that meets it, otherwise it flags low-odds targets.">
                                    <i class="o-question-mark-circle"></i>
                                </span>
                            </label>
                            <input type="number" name="settings[min_success_chance]" class="form-control" min="0" max="100" step="1" value="65" placeholder="e.g. 65">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@pushOnce('scripts', 'spy-campaign-tooltips')
    <script>
        document.addEventListener('codex:page-ready', () => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach((tooltipTriggerEl) => {
                });
        });
    </script>
@endPushOnce
