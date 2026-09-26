@extends('layouts.main')

@php
    $predictionRows = $predictions ?? collect();
    $assessment = is_array($assessment ?? null) ? $assessment : (array) ($assessment ?? []);
    $assessmentMetrics = is_array(data_get($assessment, 'metrics')) ? data_get($assessment, 'metrics') : [];
    $completedCount = (int) data_get($assessment, 'sample_count', 0);
    $victoryCalibration = (array) data_get($assessmentMetrics, 'victory_calibration', []);
    $money = static function (mixed $value): string {
        return is_numeric($value) ? '$'.number_format((float) $value, 0) : 'Unavailable';
    };
    $percent = static function (mixed $value): string {
        if (! is_numeric($value)) {
            return 'Unavailable';
        }

        return number_format((float) $value, 1).'%';
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
    $confidenceIntent = static fn (?string $confidence): string => match ($confidence) {
        'high' => 'badge-success',
        'medium' => 'badge-warning',
        default => 'badge-ghost',
    };
    $targetId = static fn (mixed $prediction): mixed => data_get($prediction, 'target.id', data_get($prediction, 'target_nation_id'));
    $targetLabel = static fn (mixed $prediction): string => (string) data_get($prediction, 'target.leader_name', 'Unknown target');
@endphp

@section('content')
    <div class="mx-auto w-full min-w-0 space-y-6">
        <header class="nexus-page-header">
            <div class="nexus-page-header__copy">
                <p class="nexus-kicker">Offense review</p>
                <h1 class="nexus-page-title">My raid results</h1>
                <p class="nexus-page-summary">
                    Review what each declaration was expected to return and what the completed war delivered.
                </p>
            </div>
            <div class="nexus-page-header__actions">
                <a href="{{ route('defense.raid-finder') }}" class="btn btn-primary btn-sm">Find a target</a>
            </div>
        </header>

        <section class="nexus-metrics" aria-label="Raid prediction summary">
            <div class="nexus-metric">
                <span class="nexus-stat-label">Completed raids</span>
                <strong class="nexus-stat-value">{{ number_format($completedCount) }}</strong>
                <span class="nexus-stat-helper">Reconciled in the last 30 days</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Mean error</span>
                <strong class="nexus-stat-value">{{ $money(data_get($assessmentMetrics, 'mean_absolute_error')) }}</strong>
                <span class="nexus-stat-helper">Absolute net-return error</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Range coverage</span>
                <strong class="nexus-stat-value">{{ $percent(data_get($assessmentMetrics, 'range_coverage.percent')) }}</strong>
                <span class="nexus-stat-helper">Actual net within the expected range</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Victory calibration</span>
                <strong class="nexus-stat-value">{{ $percent(data_get($victoryCalibration, 'actual_percent')) }}</strong>
                <span class="nexus-stat-helper">Won, against {{ $percent(data_get($victoryCalibration, 'predicted_percent')) }} predicted</span>
            </div>
        </section>

        <section class="nexus-panel" aria-labelledby="raid-results-heading">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="raid-results-heading" class="nexus-section-title">Declaration history</h2>
                    <p class="mt-1 text-sm text-base-content/65">The expected return is the finder valuation frozen at declaration, compared with what the war delivered.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table" data-sortable="false">
                    <caption class="sr-only">Your raid predictions and reconciled outcomes.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Target</th>
                            <th scope="col">Declared</th>
                            <th scope="col">Expected</th>
                            <th scope="col">Actual net</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Confidence</th>
                            <th scope="col">Finder rank</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($predictionRows as $prediction)
                            @php
                                $status = (string) data_get($prediction, 'outcome_status', data_get($prediction, 'capture_status', 'open'));
                                $statusIntent = in_array(strtolower($status), ['won', 'completed', 'complete', 'reconciled'], true)
                                    ? 'badge-success'
                                    : (in_array(strtolower($status), ['lost', 'failed', 'incomplete'], true) ? 'badge-error' : 'badge-ghost');
                                $confidence = data_get($prediction, 'confidence');
                                $actualValue = data_get($prediction, 'actual_net');
                                $finderRank = data_get($prediction, 'finder_rank');
                            @endphp
                            <tr>
                                <td>
                                    <div class="font-semibold">
                                        <x-pw-nation-link :nation-id="$targetId($prediction)" :label="$targetLabel($prediction)" />
                                    </div>
                                    <div class="mt-1 text-xs nexus-text-muted">War #{{ data_get($prediction, 'war_id', 'Unknown') }}</div>
                                </td>
                                <td class="whitespace-nowrap">{{ $date(data_get($prediction, 'declared_at')) }}</td>
                                <td class="tabular-nums">
                                    <div class="font-semibold">{{ $money(data_get($prediction, 'expected_net')) }}</div>
                                    @if (is_numeric(data_get($prediction, 'expected_net_low')) && is_numeric(data_get($prediction, 'expected_net_high')))
                                        <div class="text-xs nexus-text-muted">{{ $money(data_get($prediction, 'expected_net_low')) }} – {{ $money(data_get($prediction, 'expected_net_high')) }}</div>
                                    @endif
                                </td>
                                <td class="tabular-nums {{ is_numeric($actualValue) && (float) $actualValue < 0 ? 'text-error' : '' }}">{{ is_numeric($actualValue) ? $money($actualValue) : 'Pending' }}</td>
                                <td><span class="badge {{ $statusIntent }}">{{ str($status)->headline() }}</span></td>
                                <td><span class="badge {{ $confidenceIntent($confidence) }}">{{ $confidence ? str($confidence)->headline() : 'Unknown' }}</span></td>
                                <td class="tabular-nums">{{ $finderRank ? '#'.$finderRank : 'Not from finder' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="nexus-empty-state">
                                        <x-icon name="o-chart-bar" class="size-9 nexus-text-muted" aria-hidden="true" />
                                        <div>
                                            <h3 class="font-semibold">No raid predictions yet</h3>
                                            <p class="mt-1 text-sm text-base-content/65">Predictions appear here when you declare a raid war.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
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
