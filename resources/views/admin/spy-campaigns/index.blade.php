@extends('layouts.admin')

@section('content')
    <x-header title="Spy Campaigns" separator>
        <x-slot:subtitle>Plan espionage rounds, monitor odds, and dispatch assignments.</x-slot:subtitle>
        <x-slot:actions>
            <div class="tooltip tooltip-left" data-tip="Coordinate espionage rounds, auto-build matchups, then message aggressors with one click.">
                <button class="btn btn-primary btn-sm" type="button" onclick="document.getElementById('createSpyCampaignModal').showModal()">
                    <x-icon name="o-plus-circle" class="size-4" />
                    New Campaign
                </button>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Active campaigns" :value="number_format($campaigns->where('status', 'active')->count())" icon="o-bolt" color="text-success" description="Campaigns currently running" />
        <x-stat title="Total rounds" :value="number_format($campaigns->sum('rounds_count'))" icon="o-rectangle-stack" description="Rounds across all campaigns" />
        <x-stat title="Assignments queued" :value="number_format($campaigns->sum('assignments_count'))" icon="o-paper-airplane" color="text-info" description="Generated orders" />
        <x-stat title="Avg latest odds" :value="number_format($stats->avg('avg_odds'), 1) . '%'" icon="o-chart-bar-square" color="text-warning" description="Average across the newest round of each campaign" />
    </div>

    <x-card title="Campaign list" :subtitle="$campaigns->count() . ' campaigns tracked'">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Alliances</th>
                    <th>Rounds</th>
                    <th>Assignments</th>
                    <th>Avg Odds</th>
                    <th>High Impact</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($campaigns as $campaign)
                    @php
                        $campaignStat = $stats->firstWhere('id', $campaign->id);
                        $statusValue = $campaign->status instanceof \BackedEnum ? $campaign->status->value : (string) $campaign->status;
                        $statusClass = match ($statusValue) {
                            'active' => 'badge-success',
                            'draft' => 'badge-ghost',
                            default => 'badge-neutral',
                        };
                    @endphp
                    <tr>
                        <td>
                            <div class="font-semibold">{{ $campaign->name }}</div>
                            <div class="text-sm text-base-content/60">{{ \Illuminate\Support\Str::limit($campaign->description, 80) }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $statusClass }}">{{ ucfirst($statusValue) }}</span>
                        </td>
                        <td>
                            <span class="badge badge-primary">{{ $campaign->alliances_count }}</span>
                        </td>
                        <td>{{ $campaign->rounds_count }}</td>
                        <td>{{ $campaign->assignments_count }}</td>
                        <td>{{ number_format($campaignStat['avg_odds'] ?? 0, 1) }}%</td>
                        <td>{{ number_format($campaignStat['high_impact_ratio'] ?? 0, 1) }}%</td>
                        <td class="text-right">
                            <a href="{{ route('admin.spy-campaigns.show', $campaign) }}" class="btn btn-primary btn-outline btn-sm">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-6 text-center text-sm text-base-content/60">No spy campaigns configured yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <dialog id="createSpyCampaignModal" class="modal">
        <div class="modal-box max-w-2xl">
            <form method="post" action="{{ route('admin.spy-campaigns.store') }}" class="space-y-4">
                @csrf

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">New Spy Campaign</h3>
                        <p class="text-sm text-base-content/60">Create the campaign shell before adding allied and enemy alliances.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="document.getElementById('createSpyCampaignModal').close()">✕</button>
                </div>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Name</span>
                    <input type="text" name="name" class="input input-bordered w-full" required>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium">Description</span>
                    <textarea name="description" class="textarea textarea-bordered min-h-28 w-full" placeholder="What is the goal of this spy campaign?"></textarea>
                </label>

                <label class="block space-y-2">
                    <span class="flex items-center gap-2 text-sm font-medium">
                        Min success target (%)
                        <span class="tooltip" data-tip="Lowest success chance acceptable for this campaign. Assignment generation will pick the lowest safety level that meets it, otherwise it flags low-odds targets.">
                            <x-icon name="o-question-mark-circle" class="size-4 text-base-content/50" />
                        </span>
                    </span>
                    <input type="number" name="settings[min_success_chance]" class="input input-bordered w-full" min="0" max="100" step="1" value="65" placeholder="e.g. 65">
                </label>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('createSpyCampaignModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
@endsection
