@extends('layouts.admin')

@php
    $predictionRows = $predictions ?? collect();
    $assessment = is_array($assessment ?? null) ? $assessment : (array) ($assessment ?? []);
    $assessmentMetrics = is_array(data_get($assessment, 'metrics')) ? data_get($assessment, 'metrics') : [];
    $capturedCount = (int) data_get($assessment, 'capture.total', 0);
    $completedCount = (int) data_get($assessment, 'sample_count', 0);
    $money = static function (mixed $value): string {
        return is_numeric($value) ? '$'.number_format((float) $value, 0) : 'Unavailable';
    };
    $percent = static function (mixed $value): string {
        if (! is_numeric($value)) {
            return 'Unavailable';
        }

        return number_format((float) $value, 1).'%';
    };
    $hours = static function (mixed $value): string {
        return is_numeric($value) ? number_format((float) $value, 1).'h' : 'Unavailable';
    };
    $date = static function (mixed $value): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('M j, Y g:i A');
        }

        if (! filled($value)) {
            return 'Not recorded';
        }

        try {
            return \Carbon\CarbonImmutable::parse((string) $value)->format('M j, Y g:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    };
    $breakdownRows = collect();
    foreach ((array) data_get($assessment, 'breakdowns', []) as $dimension => $groups) {
        if (! is_array($groups)) {
            continue;
        }

        foreach ($groups as $group => $row) {
            if (is_array($row) && array_key_exists('metrics', $row)) {
                $breakdownRows->push([
                    'label' => str((string) $dimension)->headline()->toString().' · '.str((string) $group)->headline()->toString(),
                    'samples' => data_get($row, 'sample_count', 0),
                    ...((array) data_get($row, 'metrics', [])),
                ]);
            }
        }
    }
    $componentRows = collect(data_get($assessmentMetrics, 'component_errors', []))->map(
        static fn (mixed $value, mixed $key): array => ['label' => str((string) $key)->headline()->toString(), 'value' => $value],
    )->values();
@endphp

@section('title', 'Raid prediction assessment')

@section('content')
    <div data-raid-assessment class="space-y-6">
        <x-header title="Raid prediction assessment" separator use-h1>
            <x-slot:subtitle>Review prediction accuracy from member-declared raid wars and the evidence behind each result.</x-slot:subtitle>
            <x-slot:actions>
                <a href="{{ route('admin.raids.index') }}" class="btn btn-outline btn-sm">Raid settings</a>
            </x-slot:actions>
        </x-header>

        <section class="nexus-metrics" aria-label="Raid assessment summary">
            <div class="nexus-metric">
                <span class="nexus-stat-label">Predictions</span>
                <strong class="nexus-stat-value">{{ number_format($capturedCount) }}</strong>
                <span class="nexus-stat-helper">Rolling {{ number_format((float) data_get($assessment, 'window.days', 30), 0) }}-day window</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Completed</span>
                <strong class="nexus-stat-value">{{ number_format($completedCount) }}</strong>
                <span class="nexus-stat-helper">Outcomes reconciled</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Mean absolute error</span>
                <strong class="nexus-stat-value">{{ $money(data_get($assessmentMetrics, 'mean_absolute_error')) }}</strong>
                <span class="nexus-stat-helper">Net-return error</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Signed error</span>
                <strong class="nexus-stat-value">{{ $money(data_get($assessmentMetrics, 'signed_error')) }}</strong>
                <span class="nexus-stat-helper">Positive means under-predicted</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Scenario coverage</span>
                <strong class="nexus-stat-value">{{ $percent(data_get($assessmentMetrics, 'range_coverage.percent')) }}</strong>
                <span class="nexus-stat-helper">Outcomes inside predicted range</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Duration error</span>
                <strong class="nexus-stat-value">{{ $hours(data_get($assessmentMetrics, 'duration_error_hours')) }}</strong>
                <span class="nexus-stat-helper">Actual minus predicted</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Known war capture coverage</span>
                <strong class="nexus-stat-value">{{ $percent(data_get($assessment, 'capture.coverage.percent')) }}</strong>
                <span class="nexus-stat-helper">Qualifying wars since the capture baseline</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Incomplete captures</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($assessment, 'capture.incomplete', 0)) }}</strong>
                <span class="nexus-stat-helper">Missing clean declaration-time inputs</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Evaluation failures</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($assessment, 'capture.evaluation_failed', 0)) }}</strong>
                <span class="nexus-stat-helper">Inspect declaration status for the reason</span>
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-2">
            <section class="nexus-panel" aria-labelledby="raid-assessment-components">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="raid-assessment-components" class="nexus-section-title">Error by component</h2>
                        <p class="mt-1 text-sm text-base-content/65">Use these values to find which inputs need recalibration.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Component</th><th>Signed error</th></tr></thead>
                        <tbody>
                            @forelse ($componentRows as $component)
                                <tr>
                                    <td class="font-medium">{{ data_get($component, 'label', 'Unknown') }}</td>
                                    <td class="tabular-nums">{{ $money(data_get($component, 'value')) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="nexus-text-muted">No component assessment is available yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="nexus-panel" aria-labelledby="raid-assessment-breakdowns">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="raid-assessment-breakdowns" class="nexus-section-title">Breakdowns</h2>
                        <p class="mt-1 text-sm text-base-content/65">Accuracy grouped by activity, intelligence age, confidence, and competition.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Group</th><th>Samples</th><th>Mean error</th><th>Range coverage</th></tr></thead>
                        <tbody>
                            @forelse ($breakdownRows as $breakdown)
                                <tr>
                                    <td class="font-medium">{{ data_get($breakdown, 'label', 'Unknown') }}</td>
                                    <td class="tabular-nums">{{ number_format((int) data_get($breakdown, 'samples', 0)) }}</td>
                                    <td class="tabular-nums">{{ $money(data_get($breakdown, 'mean_absolute_error')) }}</td>
                                    <td class="tabular-nums">{{ $percent(data_get($breakdown, 'range_coverage.percent')) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="nexus-text-muted">No grouped assessment is available yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="nexus-panel" aria-labelledby="raid-assessment-predictions">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="raid-assessment-predictions" class="nexus-section-title">Captured declarations</h2>
                    <p class="mt-1 text-sm text-base-content/65">Every member Raid declaration is captured at the declaration boundary; attacks from that war are excluded from its baseline.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table" data-sortable="false">
                    <caption class="sr-only">Member raid predictions and outcomes.</caption>
                    <thead>
                        <tr><th scope="col">Member</th><th scope="col">Target</th><th scope="col">Declared</th><th scope="col">Predicted</th><th scope="col">Actual</th><th scope="col">Difference</th><th scope="col">Status</th><th scope="col">Model</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($predictionRows as $prediction)
                            @php
                                $predicted = data_get($prediction, 'expected_net');
                                $actual = data_get($prediction, 'actual_net');
                                $difference = is_numeric($actual) && is_numeric($predicted) ? (float) $actual - (float) $predicted : null;
                                $status = (string) data_get($prediction, 'outcome_status', data_get($prediction, 'capture_status', data_get($prediction, 'evaluation_status', 'open')));
                                $statusIntent = in_array(strtolower($status), ['completed', 'complete', 'reconciled', 'won'], true) ? 'badge-success' : (in_array(strtolower($status), ['failed', 'lost', 'incomplete'], true) ? 'badge-error' : 'badge-ghost');
                                $memberId = data_get($prediction, 'attacker.id', data_get($prediction, 'attacker_nation_id'));
                                $memberLabel = data_get($prediction, 'attacker.leader_name', 'Unknown member');
                                $targetNationId = data_get($prediction, 'target.id', data_get($prediction, 'target_nation_id'));
                                $targetName = data_get($prediction, 'target.leader_name', 'Unknown target');
                            @endphp
                            <tr>
                                <td><x-pw-nation-link :nation-id="$memberId" :label="$memberLabel" /></td>
                                <td><x-pw-nation-link :nation-id="$targetNationId" :label="$targetName" /><div class="mt-1 text-xs nexus-text-muted">War #{{ data_get($prediction, 'war_id', 'Unknown') }}</div></td>
                                <td class="whitespace-nowrap">{{ $date(data_get($prediction, 'declared_at')) }}</td>
                                <td class="font-semibold tabular-nums">{{ $money($predicted) }}</td>
                                <td class="tabular-nums">{{ $money($actual) }}</td>
                                <td class="tabular-nums {{ is_numeric($difference) && $difference < 0 ? 'text-error' : 'text-success' }}">{{ is_numeric($difference) ? ($difference >= 0 ? '+' : '').$money($difference) : 'Pending' }}</td>
                                <td><span class="badge {{ $statusIntent }}">{{ str($status)->headline() }}</span></td>
                                <td class="text-xs nexus-text-muted">{{ data_get($prediction, 'model_version', 'Unknown') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="nexus-empty-state"><x-icon name="o-chart-bar" class="size-9 nexus-text-muted" aria-hidden="true" /><div><h3 class="font-semibold">No declarations captured yet</h3><p class="mt-1 text-sm text-base-content/65">Member raid wars will appear here as events arrive.</p></div></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (is_object($predictionRows) && method_exists($predictionRows, 'hasPages') && $predictionRows->hasPages())
                <div class="nexus-panel__footer">{{ $predictionRows->withQueryString()->links() }}</div>
            @endif
        </section>
    </div>
@endsection
