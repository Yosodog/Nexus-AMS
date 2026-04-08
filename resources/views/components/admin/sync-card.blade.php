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

    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
        <dt class="text-base-content/60 font-medium">Last Ran</dt>
        <dd>{{ $batch?->finishedAt ?? 'Never' }}</dd>

        <dt class="text-base-content/60 font-medium">Status</dt>
        <dd>
            @if($batch)
                @if($batch->cancelled())
                    <x-badge  value="Cancelled" icon="o-x-circle" class="badge-error badge-sm" />
                @elseif($batch->finished())
                    <x-badge  value="Finished" icon="o-check-circle" class="badge-success badge-sm" />
                @else
                    <x-badge  value="Running" icon="o-clock" class="badge-warning badge-sm" />
                @endif
            @else
                <span class="text-base-content/50">Idle</span>
            @endif
        </dd>

        @if($batch)
            <dt class="text-base-content/60 font-medium">Jobs Completed</dt>
            <dd>{{ $batch->processedJobs() }} / {{ $batch->totalJobs }}</dd>

            <dt class="text-base-content/60 font-medium">Progress</dt>
            <dd>
                <x-progress value="{{ $batch->progress() }}" class="progress-success h-3" />
                <span class="text-xs text-base-content/60 mt-0.5">{{ round($batch->progress()) }}%</span>
            </dd>

            <dt class="text-base-content/60 font-medium">Failed Jobs</dt>
            <dd class="text-error font-semibold">{{ $batch->failedJobs }}</dd>

            <dt class="text-base-content/60 font-medium">Started</dt>
            <dd>{{ $batch->createdAt->diffForHumans() }}</dd>

            @if($batch->finishedAt)
                <dt class="text-base-content/60 font-medium">Finished</dt>
                <dd>{{ $batch->finishedAt->diffForHumans() }}</dd>
            @endif

            @if($batch->cancelledAt)
                <dt class="text-base-content/60 font-medium">Cancelled</dt>
                <dd class="text-error">{{ $batch->cancelledAt->diffForHumans() }}</dd>
            @endif
        @endif
    </dl>
</x-card>
