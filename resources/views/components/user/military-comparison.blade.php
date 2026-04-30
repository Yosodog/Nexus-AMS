@props(['nation', 'latestSignIn' => null, 'requirements' => [], 'meets' => false])

<div>
    <div class="nexus-user-section-head">
        <div>
            <p class="nexus-user-eyebrow">Force posture</p>
            <h3 class="nexus-user-section-title">Military compliance</h3>
            <p class="nexus-user-section-copy">Minimum counts are derived from your current city tier and latest sync.</p>
        </div>
        <span class="nexus-user-status-pill {{ $meets ? 'nexus-user-status-pill-success' : 'nexus-user-status-pill-warning' }}">
            {{ $meets ? 'Ready for ops' : 'Needs build' }}
        </span>
    </div>

    @php
        $units = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'];
    @endphp

    <div class="mt-5 space-y-4 xl:hidden">
        @foreach ($units as $unit)
            @php
                $current = $latestSignIn->$unit ?? $nation->$unit ?? 0;
                $required = $requirements[$unit] ?? 0;
                $percent = $required > 0 ? min(100, round(($current / $required) * 100, 1)) : 100;
                $met = $required === 0 ? true : $current >= $required;
            @endphp
            <div class="nexus-user-divider-y pt-4 first:border-t-0 first:pt-0">
                <div class="flex items-center justify-between gap-3">
                    <p class="font-semibold capitalize text-base-content">{{ $unit }}</p>
                    <span class="nexus-user-status-pill {{ $met ? 'nexus-user-status-pill-success' : 'nexus-user-status-pill-warning' }}">
                        {{ $met ? 'On target' : 'Needs build' }}
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="nexus-user-data-label">Current</p>
                        <p class="mt-1 font-semibold text-base-content">{{ number_format($current) }}</p>
                    </div>
                    <div>
                        <p class="nexus-user-data-label">Required</p>
                        <p class="mt-1 font-semibold text-base-content">{{ number_format($required) }}</p>
                    </div>
                    <div class="col-span-2">
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
                    <th>Unit</th>
                    <th class="text-right">Current</th>
                    <th class="text-right">Required</th>
                    <th class="w-56">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($units as $unit)
                    @php
                        $current = $latestSignIn->$unit ?? $nation->$unit ?? 0;
                        $required = $requirements[$unit] ?? 0;
                        $percent = $required > 0 ? min(100, round(($current / $required) * 100, 1)) : 100;
                        $met = $required === 0 ? true : $current >= $required;
                    @endphp
                    <tr>
                        <td class="font-semibold capitalize text-base-content">{{ $unit }}</td>
                        <td class="text-right tabular-nums">{{ number_format($current) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($required) }}</td>
                        <td class="w-56">
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="font-semibold text-base-content">{{ $percent }}%</span>
                                    <span class="nexus-user-status-pill {{ $met ? 'nexus-user-status-pill-success' : 'nexus-user-status-pill-warning' }}">
                                        {{ $met ? 'On target' : 'Needs build' }}
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
</div>
