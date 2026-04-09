@props(['title', 'batch', 'route'])

<x-card :title="$title" separator>
    <x-slot:menu>
        @if($batch && !$batch->finished())
            <div class="flex items-center gap-2">
                <x-button label="Syncing..." icon="o-arrow-path" class="btn-sm btn-ghost" disabled>
                    <x-slot:icon>
                        <span class="loading loading-spinner loading-xs"></span>
                    </x-slot:icon>
                </x-button>
                <form method="POST" action="{{ route('admin.settings.sync.cancel') }}"
                      onsubmit="return confirm('Are you sure you want to cancel this sync?')">
                    @csrf
                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                    <input type="hidden" name="type" value="{{ strtolower(Str::before($title, ' ')) }}">
                    <x-button label="Cancel" icon="o-x-circle" type="submit" class="btn-sm btn-error btn-outline" />
                </form>
            </div>
        @else
            <form method="POST" action="{{ $route }}">
                @csrf
                <x-button label="Run Sync" icon="o-arrow-path" type="submit" class="btn-sm btn-primary btn-outline" />
            </form>
        @endif
    </x-slot:menu>

    @if($batch)
        @php
            $progressPercent = (float) $batch->progress();
            $statusValue = $batch->cancelled() ? 'Cancelled' : ($batch->finished() ? 'Finished' : 'Running');
            $statusClass = $batch->cancelled() ? 'badge-error' : ($batch->finished() ? 'badge-success' : 'badge-warning');
        @endphp

        <div class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-stat title="Status" :value="$statusValue" icon="o-arrow-path" color="text-primary" description="Current sync state" />
            <x-stat title="Jobs Completed" :value="$batch->processedJobs() . ' / ' . $batch->totalJobs" icon="o-check-circle" color="text-success" description="Processed versus total queued jobs" />
            <x-stat title="Last Ran" :value="$batch->finishedAt ? $batch->finishedAt->format('M d, H:i') : 'Never'" icon="o-clock" color="text-info" :description="$batch->finishedAt ? $batch->finishedAt->diffForHumans() : 'No completed sync recorded yet'" />
            <x-stat title="Failed Jobs" :value="number_format($batch->failedJobs)" icon="o-exclamation-circle" color="text-warning" description="Jobs that need inspection or retry" />
        </div>

        <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="font-semibold text-base-content">Progress</div>
                    <div class="text-sm text-base-content/60">{{ round($progressPercent) }}% complete.</div>
                </div>
                <x-badge :value="$statusValue" class="{{ $statusClass }} badge-sm" />
            </div>

            <x-progress :value="$progressPercent" class="progress-primary h-3" />

            <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="font-medium text-base-content/60">Started</dt>
                <dd>{{ $batch->createdAt->toDayDateTimeString() }} ({{ $batch->createdAt->diffForHumans() }})</dd>

                @if($batch->finishedAt)
                    <dt class="font-medium text-base-content/60">Finished</dt>
                    <dd>{{ $batch->finishedAt->toDayDateTimeString() }} ({{ $batch->finishedAt->diffForHumans() }})</dd>
                @endif

                @if($batch->cancelledAt)
                    <dt class="font-medium text-base-content/60">Cancelled</dt>
                    <dd class="text-error">{{ $batch->cancelledAt->toDayDateTimeString() }} ({{ $batch->cancelledAt->diffForHumans() }})</dd>
                @endif
            </dl>
        </div>
    @else
        <div class="rounded-2xl border border-dashed border-base-300 px-4 py-6 text-sm text-base-content/60">
            No sync is currently active.
        </div>
    @endif
</x-card>
