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
    $ranking = (array) data_get($assessment, 'ranking', []);
    $estimator = (array) data_get($assessment, 'estimator', []);
    $estimatorRows = collect();
    foreach ((array) data_get($estimator, 'breakdowns', []) as $dimension => $groups) {
        foreach ((array) $groups as $group => $row) {
            $estimatorRows->push([
                'label' => str((string) $dimension)->headline()->toString().' · '.str((string) $group)->headline()->toString(),
                ...((array) $row),
            ]);
        }
    }
    $ratio = static fn (mixed $value): string => is_numeric($value) ? number_format((float) $value, 2).'×' : 'Unavailable';
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

        <h2 class="nexus-section-title">Member raids</h2>
        <section class="nexus-metrics" aria-label="Member raid assessment summary">
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
                <span class="nexus-stat-label">Range coverage</span>
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
                <span class="nexus-stat-label">Victory calibration</span>
                <strong class="nexus-stat-value">{{ $percent(data_get($assessmentMetrics, 'victory_calibration.actual_percent')) }}</strong>
                <span class="nexus-stat-helper">Won, against {{ $percent(data_get($assessmentMetrics, 'victory_calibration.predicted_percent')) }} predicted</span>
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

        <div class="grid items-start gap-6 xl:grid-cols-2">
            <section class="nexus-panel" aria-labelledby="raid-assessment-ranking">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="raid-assessment-ranking" class="nexus-section-title">Ranking quality</h2>
                        <p class="mt-1 text-sm text-base-content/65">Completed raids grouped by the finder rank the target was shown at. Top-5 share: {{ $percent(data_get($ranking, 'top5_share')) }}.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Finder rank</th><th>Raids</th><th>Mean expected</th><th>Mean actual</th></tr></thead>
                        <tbody>
                            @foreach ((array) data_get($ranking, 'buckets', []) as $bucket => $row)
                                <tr>
                                    <td class="font-medium">{{ $bucket === 'not_from_finder' ? 'Not from finder' : str_replace(['_plus', '_'], ['+', '–'], $bucket) }}</td>
                                    <td class="tabular-nums">{{ number_format((int) data_get($row, 'count', 0)) }}</td>
                                    <td class="tabular-nums">{{ $money(data_get($row, 'mean_expected_net')) }}</td>
                                    <td class="tabular-nums">{{ $money(data_get($row, 'mean_actual_net')) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="nexus-panel" aria-labelledby="raid-assessment-estimator">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="raid-assessment-estimator" class="nexus-section-title">Stockpile estimator (world)</h2>
                        <p class="mt-1 text-sm text-base-content/65">
                            Every victory in the world compares the stockpile a profile predicted with the stockpile its loot revealed.
                            Parameters calibrated {{ data_get($estimator, 'model_parameters_computed_at') ? $date(data_get($estimator, 'model_parameters_computed_at')) : 'never' }}.
                        </p>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-4 px-4 pb-4 sm:grid-cols-4">
                    <div><dt class="text-xs nexus-text-muted">Backtests</dt><dd class="font-semibold tabular-nums">{{ number_format((int) data_get($estimator, 'sample_count', 0)) }}</dd></div>
                    <div><dt class="text-xs nexus-text-muted">Median revealed ÷ predicted</dt><dd class="font-semibold tabular-nums">{{ $ratio(data_get($estimator, 'median_ratio')) }}</dd></div>
                    <div><dt class="text-xs nexus-text-muted">Median absolute error</dt><dd class="font-semibold tabular-nums">{{ $percent(data_get($estimator, 'median_absolute_percent_error')) }}</dd></div>
                    <div><dt class="text-xs nexus-text-muted">Interval coverage</dt><dd class="font-semibold tabular-nums">{{ $percent(data_get($estimator, 'interval_coverage_percent')) }}</dd></div>
                </dl>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Group</th><th>Backtests</th><th>Median ratio</th><th>Median error</th><th>Coverage</th></tr></thead>
                        <tbody>
                            @forelse ($estimatorRows as $row)
                                <tr>
                                    <td class="font-medium">{{ data_get($row, 'label') }}</td>
                                    <td class="tabular-nums">{{ number_format((int) data_get($row, 'sample_count', 0)) }}</td>
                                    <td class="tabular-nums">{{ $ratio(data_get($row, 'median_ratio')) }}</td>
                                    <td class="tabular-nums">{{ $percent(data_get($row, 'median_absolute_percent_error')) }}</td>
                                    <td class="tabular-nums">{{ $percent(data_get($row, 'interval_coverage_percent')) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="nexus-text-muted">No victory backtests are available yet.</td></tr>
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
                        <tr><th scope="col">Member</th><th scope="col">Target</th><th scope="col">Declared</th><th scope="col">Predicted</th><th scope="col">Finder rank</th><th scope="col">Actual</th><th scope="col">Difference</th><th scope="col">Status</th><th scope="col">Model</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($predictionRows as $prediction)
                            @php
                                $predicted = data_get($prediction, 'expected_net');
                                $actual = data_get($prediction, 'actual_net');
                                $difference = is_numeric($actual) && is_numeric($predicted) ? (float) $actual - (float) $predicted : null;
                                $status = (string) data_get($prediction, 'outcome_status', data_get($prediction, 'capture_status', 'open'));
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
                                <td class="tabular-nums">
                                    <div class="font-semibold">{{ $money($predicted) }}</div>
                                    @if (is_numeric(data_get($prediction, 'expected_net_low')) && is_numeric(data_get($prediction, 'expected_net_high')))
                                        <div class="text-xs nexus-text-muted">{{ $money(data_get($prediction, 'expected_net_low')) }} – {{ $money(data_get($prediction, 'expected_net_high')) }}</div>
                                    @endif
                                </td>
                                <td class="tabular-nums">{{ data_get($prediction, 'finder_rank') ? '#'.data_get($prediction, 'finder_rank') : '—' }}</td>
                                <td class="tabular-nums">{{ $money($actual) }}</td>
                                <td class="tabular-nums {{ is_numeric($difference) && $difference < 0 ? 'text-error' : 'text-success' }}">{{ is_numeric($difference) ? ($difference >= 0 ? '+' : '').$money($difference) : 'Pending' }}</td>
                                <td><span class="badge {{ $statusIntent }}">{{ str($status)->headline() }}</span></td>
                                <td class="text-xs nexus-text-muted">{{ data_get($prediction, 'model_version', 'Unknown') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9"><div class="nexus-empty-state"><x-icon name="o-chart-bar" class="size-9 nexus-text-muted" aria-hidden="true" /><div><h3 class="font-semibold">No declarations captured yet</h3><p class="mt-1 text-sm text-base-content/65">Member raid wars will appear here as events arrive.</p></div></div></td></tr>
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
