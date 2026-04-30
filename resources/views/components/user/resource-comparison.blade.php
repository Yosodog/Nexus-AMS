@props(['resources' => [], 'weights' => []])

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
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
            <div class="mt-3 space-y-3 xl:hidden">
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
                    <div class="rounded-xl border border-base-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold capitalize">{{ $res }}</p>
                            <span class="badge {{ $met ? 'badge-success badge-outline badge-nowrap' : 'badge-warning badge-outline badge-nowrap' }}">
                                {{ $met ? 'On target' : 'Needs build' }}
                            </span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                            <div class="rounded-lg bg-base-200/60 px-2 py-1">
                                <p class="text-base-content/60">Current</p>
                                <p class="font-semibold text-sm">{{ number_format($have) }}</p>
                            </div>
                            <div class="rounded-lg bg-base-200/60 px-2 py-1">
                                <p class="text-base-content/60">Required</p>
                                <p class="font-semibold text-sm">{{ number_format($required) }}</p>
                            </div>
                            <div class="rounded-lg bg-base-200/60 px-2 py-1">
                                <p class="text-base-content/60">Weight</p>
                                <p class="font-semibold text-sm">{{ number_format($weight, 2) }}%</p>
                            </div>
                            <div class="rounded-lg bg-base-200/60 px-2 py-1">
                                <p class="text-base-content/60">Progress</p>
                                <p class="font-semibold text-sm">{{ $percent }}%</p>
                            </div>
                        </div>
                        <progress class="progress {{ $met ? 'progress-primary' : 'progress-warning' }} mt-2 w-full" value="{{ $percent }}" max="100"></progress>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 hidden overflow-x-auto xl:block">
                <table class="table table-zebra md:table-fixed">
                    <thead>
                    <tr>
                        <th class="whitespace-nowrap">Resource</th>
                        <th class="whitespace-nowrap text-right">Current</th>
                        <th class="whitespace-nowrap text-right">Required</th>
                        <th class="whitespace-nowrap text-right">Weight</th>
                        <th class="w-48">Status</th>
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
                            <td class="capitalize whitespace-nowrap">{{ $res }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">{{ number_format($have) }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">{{ number_format($required) }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">{{ number_format($weight, 2) }}%</td>
                            <td class="w-48">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-semibold">{{ $percent }}%</span>
                                        <span class="badge {{ $met ? 'badge-success badge-outline badge-nowrap' : 'badge-warning badge-outline badge-nowrap' }}">
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
