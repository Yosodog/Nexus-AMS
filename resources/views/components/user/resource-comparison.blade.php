@props(['resources' => [], 'weights' => []])

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="card-title">Resources vs Required</h3>
                <p class="text-sm text-base-content/70">Totals include per-city requirements multiplied by your city count.</p>
            </div>
            @if(!empty($resources))
                <div class="badge badge-outline">
                    Weighted total {{ number_format(array_sum($weights ?? []), 0) }}%
                </div>
            @endif
        </div>

        @if(empty($resources))
            <p class="text-sm text-base-content/70 mt-2">No MMR snapshot yet. Sign in to see your resource compliance.</p>
        @else
            @php
                $resourceOrder = ['money', 'steel', 'aluminum', 'munitions', 'gasoline', 'uranium', 'food'];
            @endphp
            <div class="overflow-x-auto mt-3">
                <table class="table table-zebra">
                    <thead>
                    <tr>
                        <th>Resource</th>
                        <th>Current</th>
                        <th>Required</th>
                        <th>Weight</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($resourceOrder as $res)
                        @php
                            $row = $resources[$res] ?? [];
                            $have = $row['have'] ?? 0;
                            $required = $row['required'] ?? 0;
                            $progress = $row['progress'] ?? 0;
                            $percent = $required <= 0 ? 100 : min(100, round($progress * 100, 1));
                            $met = $row['met'] ?? false;
                            $weight = $row['weight'] ?? ($weights[$res] ?? 0);
                        @endphp
                        <tr>
                            <td class="capitalize">{{ $res }}</td>
                            <td>{{ number_format($have) }}</td>
                            <td>{{ number_format($required) }}</td>
                            <td>{{ number_format($weight, 2) }}%</td>
                            <td>
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-semibold">{{ $percent }}%</span>
                                        <span class="badge {{ $met ? 'badge-success badge-outline' : 'badge-warning badge-outline' }}">
                                            {{ $met ? 'On target' : 'Needs build' }}
                                        </span>
                                    </div>
                                    <progress class="progress {{ $met ? 'progress-primary' : 'progress-warning' }} w-full" value="{{ $percent }}" max="100"></progress>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
