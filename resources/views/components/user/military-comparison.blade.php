@props(['nation', 'latestSignIn' => null, 'requirements' => [], 'meets' => false])

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="card-title">Military vs Required</h3>
                <p class="text-sm text-base-content/70">Minimum counts based on your current city tier.</p>
            </div>
            <span class="badge {{ $meets ? 'badge-success badge-outline badge-nowrap' : 'badge-warning badge-outline badge-nowrap' }}">
                {{ $meets ? 'Ready for duty' : 'Needs build' }}
            </span>
        </div>

        @php
            $units = ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'];
        @endphp

        <div class="mt-3 space-y-3 xl:hidden">
            @foreach ($units as $unit)
                @php
                    $current = $latestSignIn->$unit ?? $nation->$unit ?? 0;
                    $required = $requirements[$unit] ?? 0;
                    $percent = $required > 0 ? min(100, round(($current / $required) * 100, 1)) : 100;
                    $met = $required === 0 ? true : $current >= $required;
                @endphp
                <div class="rounded-xl border border-base-200 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold capitalize">{{ $unit }}</p>
                        <span class="badge {{ $met ? 'badge-success badge-outline badge-nowrap' : 'badge-warning badge-outline badge-nowrap' }}">
                            {{ $met ? 'On target' : 'Needs build' }}
                        </span>
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-lg bg-base-200/60 px-2 py-1">
                            <p class="text-base-content/60">Current</p>
                            <p class="font-semibold text-sm">{{ number_format($current) }}</p>
                        </div>
                        <div class="rounded-lg bg-base-200/60 px-2 py-1">
                            <p class="text-base-content/60">Required</p>
                            <p class="font-semibold text-sm">{{ number_format($required) }}</p>
                        </div>
                        <div class="col-span-2 rounded-lg bg-base-200/60 px-2 py-1">
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
                    <th>Unit</th>
                    <th>Current</th>
                    <th>Required</th>
                    <th>Status</th>
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
                        <td class="capitalize">{{ $unit }}</td>
                        <td>{{ number_format($current) }}</td>
                        <td>{{ number_format($required) }}</td>
                        <td>
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
    </div>
</div>
