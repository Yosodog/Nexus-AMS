@php
    $statusStyles = [
        'healthy' => [
            'badge' => 'badge-success',
            'surface' => 'bg-success/10',
            'icon' => 'o-check-circle',
            'iconColor' => 'text-success',
            'label' => 'Healthy',
        ],
        'warning' => [
            'badge' => 'badge-warning',
            'surface' => 'bg-warning/10',
            'icon' => 'o-exclamation-triangle',
            'iconColor' => 'text-warning',
            'label' => 'Warning',
        ],
        'critical' => [
            'badge' => 'badge-error',
            'surface' => 'bg-error/10',
            'icon' => 'o-x-circle',
            'iconColor' => 'text-error',
            'label' => 'Critical',
        ],
        'unknown' => [
            'badge' => 'badge-ghost',
            'surface' => 'bg-base-200',
            'icon' => 'o-question-mark-circle',
            'iconColor' => 'nexus-text-muted',
            'label' => 'Unknown',
        ],
    ];
    $overallStyle = $statusStyles[$health['status']] ?? $statusStyles['unknown'];
@endphp

<section class="mt-10" aria-labelledby="system-health-title">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="system-health-title" class="text-lg font-semibold">System health</h2>
            <p class="max-w-3xl text-sm nexus-text-muted">
                See whether scheduled tasks, connected services, and imports are keeping {{ config('app.name') }} up to date.
            </p>
        </div>
        <span class="badge {{ $overallStyle['badge'] }} gap-2" aria-label="Overall system health: {{ $overallStyle['label'] }}">
            <x-icon :name="$overallStyle['icon']" class="size-4" aria-hidden="true" />
            {{ $overallStyle['label'] }}
        </span>
    </div>

    <x-card>
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="flex items-start gap-3 rounded-lg p-4 {{ $overallStyle['surface'] }}">
                <x-icon :name="$overallStyle['icon']" class="mt-0.5 size-6 shrink-0 {{ $overallStyle['iconColor'] }}" aria-hidden="true" />
                <div>
                    <p class="font-semibold text-base-content">{{ $health['headline'] }}</p>
                    <p class="mt-1 text-sm text-base-content/70">{{ $health['summary'] }}</p>
                </div>
            </div>

            <dl class="flex flex-wrap gap-2 text-sm lg:justify-end" aria-label="Health check totals">
                <div class="badge badge-success badge-outline gap-1.5">
                    <dt>Healthy</dt>
                    <dd class="font-semibold">{{ $health['counts']['healthy'] }}</dd>
                </div>
                <div class="badge badge-warning badge-outline gap-1.5">
                    <dt>Warning</dt>
                    <dd class="font-semibold">{{ $health['counts']['warning'] }}</dd>
                </div>
                <div class="badge badge-error badge-outline gap-1.5">
                    <dt>Critical</dt>
                    <dd class="font-semibold">{{ $health['counts']['critical'] }}</dd>
                </div>
                @if ($health['counts']['unknown'] > 0)
                    <div class="badge badge-ghost gap-1.5">
                        <dt>Unknown</dt>
                        <dd class="font-semibold">{{ $health['counts']['unknown'] }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <ul class="mt-6 divide-y divide-base-300 border-y border-base-300" role="list">
            @foreach ($health['checks'] as $check)
                @php($style = $statusStyles[$check['status']] ?? $statusStyles['unknown'])
                <li class="grid gap-4 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,0.65fr)] xl:grid-cols-[minmax(16rem,1.25fr)_minmax(14rem,0.8fr)_10rem] xl:items-start">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $style['surface'] }}">
                            <x-icon :name="$style['icon']" class="size-5 {{ $style['iconColor'] }}" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-base-content">{{ $check['name'] }}</h3>
                                <span class="badge badge-sm {{ $style['badge'] }}">{{ $check['status_label'] }}</span>
                            </div>
                            <p class="mt-1 text-sm leading-5 text-base-content/70">{{ $check['description'] }}</p>
                            <p class="mt-2 text-sm text-base-content/80">{{ $check['detail'] }}</p>
                            @if ($check['status'] !== 'healthy')
                                <p class="mt-1 text-sm font-medium text-base-content">{{ $check['guidance'] }}</p>
                            @endif
                        </div>
                    </div>

                    <dl class="grid content-start gap-3 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-base-content/70">{{ $check['last_activity_label'] }}</dt>
                            <dd class="mt-0.5">
                                @if ($check['last_activity_at'])
                                    <time
                                        datetime="{{ $check['last_activity_at']->toIso8601String() }}"
                                        title="{{ $check['last_activity_at']->toDayDateTimeString() }}"
                                        class="font-medium text-base-content"
                                    >
                                        {{ $check['last_activity_at']->diffForHumans() }}
                                    </time>
                                    <span class="block text-xs text-base-content/70">{{ $check['last_activity_at']->format('M j, Y H:i T') }}</span>
                                @else
                                    <span class="text-base-content/70">Not recorded</span>
                                @endif
                            </dd>
                        </div>

                        @if ($check['secondary_label'])
                            <div>
                                <dt class="text-xs font-medium text-base-content/70">{{ $check['secondary_label'] }}</dt>
                                <dd class="mt-0.5">
                                    @if ($check['secondary_at'])
                                        <time
                                            datetime="{{ $check['secondary_at']->toIso8601String() }}"
                                            title="{{ $check['secondary_at']->toDayDateTimeString() }}"
                                            class="font-medium text-base-content"
                                        >
                                            {{ $check['secondary_at']->diffForHumans() }}
                                        </time>
                                        <span class="block text-xs text-base-content/70">{{ $check['secondary_at']->format('M j, Y H:i T') }}</span>
                                    @else
                                        <span class="text-base-content/70">No import recorded</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>

                    <div class="sm:col-span-2 xl:col-span-1 xl:text-right">
                        <div class="text-sm font-medium text-base-content/70">Expected schedule</div>
                        <div class="mt-0.5 text-sm font-medium text-base-content">{{ $check['cadence'] }}</div>
                    </div>
                </li>
            @endforeach
        </ul>

        <p class="mt-4 text-sm text-base-content/70">
            Checked <time datetime="{{ $health['checked_at']->toIso8601String() }}">{{ $health['checked_at']->format('M j, Y H:i T') }}</time>.
            These results use recent activity. Review the sync status and related details to investigate warnings.
        </p>
    </x-card>
</section>
