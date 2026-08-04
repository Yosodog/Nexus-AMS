@extends('layouts.admin')

@php
    $isPlan = $type === 'plans';
    $title = $isPlan
        ? $record->name
        : ($record->aggressor?->nation_name ? 'Counter: '.$record->aggressor->nation_name : 'Legacy counter #'.$record->id);
@endphp

@section('title', $title.' (Legacy Milcom)')

@section('content')
    <x-header separator use-h1>
        <x-slot:title>
            @if (! $isPlan && $record->aggressor)
                Counter: <x-pw-nation-link :nation-id="$record->aggressor->id" :label="$record->aggressor->nation_name ?? 'Unknown aggressor'" />
            @else
                {{ $title }}
            @endif
        </x-slot:title>
        <x-slot:subtitle>Legacy {{ $isPlan ? 'plan' : 'counter' }} #{{ number_format($record->id) }}. This record is read only and separate from v2.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.milcom.archive', ['tab' => 'legacy', 'legacy_type' => $type]) }}" class="btn btn-outline">Back to legacy history</a>
        </x-slot:actions>
    </x-header>

    @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'archive'])

    <div class="alert alert-info items-start" role="note">
        <x-icon name="o-lock-closed" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <p class="text-sm">You can view this record, but you cannot change it or send it again.</p>
    </div>

    <section class="nexus-panel">
        <div class="nexus-panel__header">
            <div><h2 class="nexus-section-title">{{ $isPlan ? 'Targets' : 'Assigned nations' }}</h2><p class="mt-1 text-sm text-base-content/65">Shows 50 rows per page.</p></div>
            <span class="badge badge-ghost">{{ str((string) $record->status)->headline() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    @if ($isPlan)
                        <tr><th>Target</th><th>Priority</th><th>War type</th><th>Team size</th><th>Last calculated</th></tr>
                    @else
                        <tr><th>Friendly nation</th><th>Match score</th><th>Status</th><th>Locked</th><th>Updated</th></tr>
                    @endif
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @if ($isPlan)
                            <tr>
                                <td>
                                    <div class="font-semibold"><x-pw-nation-link :nation-id="$row->nation_id" :label="$row->nation?->nation_name ?? 'Unknown nation'" /></div>
                                    <div class="text-xs text-base-content/55"><x-pw-nation-link :nation-id="$row->nation_id" :label="$row->nation?->leader_name ?? 'Unknown leader'" /></div>
                                </td>
                                <td class="tabular-nums">{{ number_format((float) $row->target_priority_score, 1) }}</td>
                                <td>{{ str((string) $row->preferred_war_type)->headline() }}</td>
                                <td class="tabular-nums">{{ number_format($row->assignments_count) }}</td>
                                <td>{{ $row->computed_at?->diffForHumans() ?? 'Not recorded' }}</td>
                            </tr>
                        @else
                            <tr>
                                <td>
                                    <div class="font-semibold"><x-pw-nation-link :nation-id="$row->friendly_nation_id" :label="$row->friendlyNation?->nation_name ?? 'Unknown nation'" /></div>
                                    <div class="text-xs text-base-content/55"><x-pw-nation-link :nation-id="$row->friendly_nation_id" :label="$row->friendlyNation?->leader_name ?? 'Unknown leader'" /></div>
                                </td>
                                <td class="tabular-nums">{{ number_format((float) $row->match_score, 1) }}</td>
                                <td>{{ str((string) $row->status)->headline() }}</td>
                                <td>{{ $row->is_locked ? 'Yes' : 'No' }}</td>
                                <td>{{ $row->updated_at?->diffForHumans() ?? 'Not recorded' }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5"><div class="nexus-empty-state">No rows were saved for this record.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())<div class="nexus-panel__footer">{{ $rows->links() }}</div>@endif
    </section>
@endsection
