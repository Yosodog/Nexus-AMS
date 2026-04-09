@extends('layouts.admin')

@section('content')
    <x-header :title="'Round ' . $round->round_number . ' — ' . \Illuminate\Support\Str::headline(strtolower($round->op_type?->name ?? ''))" separator>
        <x-slot:subtitle>Campaign: {{ $campaign->name }}</x-slot:subtitle>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <span class="tooltip tooltip-left" data-tip="This view lists every assignment for the round with odds, safety, and policy synergy.">
                    <x-icon name="o-question-mark-circle" class="size-5 text-base-content/50" />
                </span>
                <a href="{{ route('admin.spy-campaigns.show', $campaign) }}" class="btn btn-ghost btn-sm">Back to campaign</a>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Assignments" :value="number_format($assignments->count())" icon="o-paper-airplane" description="Orders in this round" />
        <x-stat title="Average Odds" :value="number_format($avgOdds, 1) . '%'" icon="o-chart-bar-square" color="text-warning" description="Mean assignment quality" />
        <x-stat title="High Odds (80%+)" :value="number_format($highOdds)" icon="o-shield-check" color="text-success" description="Safer operations" />
        <x-stat title="Low Odds Flags" :value="number_format($lowOdds)" icon="o-flag" color="text-error" description="Needs review" />
    </div>

    <x-card title="Assignments" subtitle="Safety levels 1/2/3 map to Quick, Normal, and Covert.">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th>Attacker</th>
                    <th>Defender</th>
                    <th>Odds</th>
                    <th>Safety</th>
                    <th>Impact</th>
                    <th>Synergy</th>
                    <th>Link</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($assignments as $assignment)
                    @php
                        $oddsBadgeClass = $assignment->calculated_odds >= 80
                            ? 'badge-success'
                            : ($assignment->low_odds_flag ? 'badge-error' : 'badge-ghost');
                        $synergyBadgeClass = $assignment->policy_synergy > 0 ? 'badge-primary' : 'badge-ghost';
                        $safetyLabels = [1 => 'Quick and Dirty', 2 => 'Normal Precautions', 3 => 'Extremely Covert'];
                        $label = $safetyLabels[$assignment->safety_level] ?? 'Unknown';
                    @endphp
                    <tr>
                        <td>
                            <div class="font-semibold">
                                <a href="https://politicsandwar.com/nation/id={{ $assignment->attacker?->id }}" target="_blank" rel="noopener" class="link link-primary">
                                    {{ $assignment->attacker?->leader_name }}
                                </a>
                            </div>
                            <div class="text-sm text-base-content/60">{{ $assignment->attacker?->nation_name }}</div>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <span class="badge badge-ghost">Score {{ number_format($assignment->attacker?->score ?? 0, 2) }}</span>
                                <span class="badge badge-ghost">Cities {{ $assignment->attacker?->num_cities ?? 0 }}</span>
                                <span class="badge badge-ghost">Spies {{ $assignment->attacker?->military?->spies ?? 0 }}</span>
                                <span class="badge badge-ghost">Policy {{ $assignment->attacker?->war_policy }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="font-semibold">
                                <a href="https://politicsandwar.com/nation/id={{ $assignment->defender?->id }}" target="_blank" rel="noopener" class="link link-primary">
                                    {{ $assignment->defender?->leader_name }}
                                </a>
                            </div>
                            <div class="text-sm text-base-content/60">{{ $assignment->defender?->nation_name }}</div>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <span class="badge badge-ghost">Score {{ number_format($assignment->defender?->score ?? 0, 2) }}</span>
                                <span class="badge badge-ghost">Cities {{ $assignment->defender?->num_cities ?? 0 }}</span>
                                <span class="badge badge-ghost">Spies {{ $assignment->defender?->military?->spies ?? 0 }}</span>
                                <span class="badge badge-ghost">Policy {{ $assignment->defender?->war_policy }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="tooltip" data-tip="{{ $assignment->low_odds_flag ? 'Below campaign threshold' : 'Meets threshold' }}">
                                <span class="badge {{ $oddsBadgeClass }}">{{ number_format($assignment->calculated_odds, 1) }}%</span>
                            </span>
                        </td>
                        <td><span class="badge badge-ghost">{{ $label }}</span></td>
                        <td>{{ number_format($assignment->expected_impact, 1) }}</td>
                        <td><span class="badge {{ $synergyBadgeClass }}">{{ number_format($assignment->policy_synergy, 2) }}</span></td>
                        <td>
                            <a href="https://politicsandwar.com/nation/espionage/eid={{ $assignment->defender?->id }}" target="_blank" rel="noopener" class="btn btn-primary btn-outline btn-sm">
                                Espionage
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center text-sm text-base-content/60">No assignments yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
