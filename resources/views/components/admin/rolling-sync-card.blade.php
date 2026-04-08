<x-card :title="'Rolling Nation Sync'" separator>
    <x-slot:menu>
        @if($batch && ! $batch->finished() && ! $batch->cancelled())
            <form method="POST" action="{{ route('admin.settings.sync.cancel') }}"
                  onsubmit="return confirm('Cancel the active rolling nation sync?')">
                @csrf
                <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                <input type="hidden" name="type" value="rolling_nation">
                <x-button label="Cancel Rolling" icon="o-x-circle" type="submit" class="btn-sm btn-error btn-outline" />
            </form>
        @else
            <x-badge :value="($rollingSchedule['scope'] ? ucfirst($rollingSchedule['scope']) : 'Unknown') . ' scope'" class="badge-ghost badge-sm" />
        @endif
    </x-slot:menu>

    <p class="mb-4 text-sm text-base-content/60">
        Scheduled command that staggers nation syncs over roughly 23 hours so the alliance roster refreshes without hammering queue capacity.
    </p>

    @if($batch)
        @php
            $progressPercent = (float) $batch->progress();
            $statusValue = $batch->cancelled() ? 'Cancelled' : ($batch->finished() ? 'Finished' : 'Running');
            $statusClass = $batch->cancelled() ? 'badge-error' : ($batch->finished() ? 'badge-success' : 'badge-warning');
        @endphp

        <div class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-stat title="Status" :value="$statusValue" icon="o-arrow-path" color="text-primary" description="Current rolling sync state" />
            <x-stat title="Jobs Completed" :value="$batch->processedJobs() . ' / ' . $batch->totalJobs" icon="o-check-circle" color="text-success" description="Processed versus total queued jobs" />
            <x-stat title="Last Job" :value="$rollingSchedule['lastRunAt'] ? $rollingSchedule['lastRunAt']->format('M d, H:i') : 'Not started'" icon="o-clock" color="text-info" :description="$rollingSchedule['lastRunAt'] ? $rollingSchedule['lastRunAt']->diffForHumans() : 'No rolling job has run yet'" />
            <x-stat title="Next Job" :value="$rollingSchedule['nextRunAt'] ? $rollingSchedule['nextRunAt']->format('M d, H:i') : ($batch->finished() ? 'Completed' : 'Pending')" icon="o-calendar-days" color="text-warning" :description="$rollingSchedule['nextRunAt'] ? $rollingSchedule['nextRunAt']->diffForHumans() : 'Waiting on scheduler state'" />
        </div>

        <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="font-semibold text-base-content">Progress</div>
                    <div class="text-sm text-base-content/60">{{ round($progressPercent) }}% complete with {{ number_format($batch->failedJobs) }} failed jobs.</div>
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
            No rolling nation sync is currently active. The scheduler will queue the next run automatically.
        </div>
    @endif
</x-card>
