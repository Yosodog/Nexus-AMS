@props(['resources' => [], 'weights' => []])

<div>
    <div class="nexus-user-section-head">
        <div>
            <p class="nexus-user-eyebrow">Stockpile</p>
            <h3 class="nexus-user-section-title">Resource compliance</h3>
            <p class="nexus-user-section-copy">Totals reflect current reserves against your doctrine requirement.</p>
        </div>
        @if(! empty($resources))
            <span class="nexus-user-status-pill nexus-user-status-pill-neutral">
                Weighted total {{ number_format(array_sum($weights ?? []), 0) }}%
            </span>
        @endif
    </div>

    @if(empty($resources))
        <div class="mt-5 text-sm text-base-content/60">
            No MMR snapshot is available yet. Sign in to Politics &amp; War to generate a stockpile comparison.
        </div>
    @else
        @php
            $resourceOrder = ['money', 'steel', 'aluminum', 'munitions', 'gasoline', 'uranium', 'food'];
        @endphp

        <div class="mt-5 space-y-4 xl:hidden">
            @foreach ($resourceOrder as $resource)
                @php
                    $row = $resources[$resource] ?? [];
                    $have = $row['have'] ?? 0;
                    $required = $row['required'] ?? 0;
                    $progress = $row['progress'] ?? 0;
                    $percent = $required <= 0 ? 100 : min(100, round($progress * 100, 1));
                    $met = $row['met'] ?? false;
                    $weight = $row['weight'] ?? ($weights[$resource] ?? 0);
                @endphp
                <div class="nexus-user-divider-y pt-4 first:border-t-0 first:pt-0">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-semibold capitalize text-base-content">{{ $resource }}</p>
                        <span class="nexus-user-status-pill {{ $met ? 'nexus-user-status-pill-success' : 'nexus-user-status-pill-warning' }}">
                            {{ $met ? 'On target' : 'Needs stockpile' }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="nexus-user-data-label">Current</p>
                            <p class="mt-1 font-semibold text-base-content">{{ number_format($have) }}</p>
                        </div>
                        <div>
                            <p class="nexus-user-data-label">Required</p>
                            <p class="mt-1 font-semibold text-base-content">{{ number_format($required) }}</p>
                        </div>
                        <div>
                            <p class="nexus-user-data-label">Weight</p>
                            <p class="mt-1 font-semibold text-base-content">{{ number_format($weight, 2) }}%</p>
                        </div>
                        <div>
                            <p class="nexus-user-data-label">Progress</p>
                            <p class="mt-1 font-semibold text-base-content">{{ $percent }}%</p>
                        </div>
                    </div>

                    <div class="mt-3 nexus-user-progress">
                        <span style="width: {{ $percent }}%"></span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-5 hidden overflow-x-auto xl:block">
            <table class="table nexus-user-table">
                <thead>
                    <tr>
                        <th>Resource</th>
                        <th class="text-right">Current</th>
                        <th class="text-right">Required</th>
                        <th class="text-right">Weight</th>
                        <th class="w-56">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resourceOrder as $resource)
                        @php
                            $row = $resources[$resource] ?? [];
                            $have = $row['have'] ?? 0;
                            $required = $row['required'] ?? 0;
                            $progress = $row['progress'] ?? 0;
                            $percent = $required <= 0 ? 100 : min(100, round($progress * 100, 1));
                            $met = $row['met'] ?? false;
                            $weight = $row['weight'] ?? ($weights[$resource] ?? 0);
                        @endphp
                        <tr>
                            <td class="font-semibold capitalize text-base-content">{{ $resource }}</td>
                            <td class="text-right tabular-nums">{{ number_format($have) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($required) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($weight, 2) }}%</td>
                            <td class="w-56">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-semibold text-base-content">{{ $percent }}%</span>
                                        <span class="nexus-user-status-pill {{ $met ? 'nexus-user-status-pill-success' : 'nexus-user-status-pill-warning' }}">
                                            {{ $met ? 'On target' : 'Needs stockpile' }}
                                        </span>
                                    </div>
                                    <div class="nexus-user-progress">
                                        <span style="width: {{ $percent }}%"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
