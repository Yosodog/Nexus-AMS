<div class="card shadow-sm mb-4 h-100">
    <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h5 class="mb-0 text-primary fw-semibold">Rolling Nation Sync</h5>
            <p class="mb-0 text-muted small">
                Scheduled command that staggers nation syncs over ~23 hours (scope: {{ $rollingSchedule['scope'] ? ucfirst($rollingSchedule['scope']) : 'unknown' }}).
            </p>
        </div>

        @if($batch && !$batch->finished() && !$batch->cancelled())
            <div class="d-flex gap-2 ms-auto">
                <form method="POST" action="{{ route('admin.settings.sync.cancel') }}"
                      onsubmit="return confirm('Cancel the active rolling nation sync?')">
                    @csrf
                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                    <input type="hidden" name="type" value="rolling_nation">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-x-circle me-1"></i> Cancel Rolling
                    </button>
                </form>
            </div>

        @endif
    </div>

    <div class="card-body small">
        @if($batch)
            @php
                $progressPercent = $batch->progress();
                $progressBarClasses = match (true) {
                    $batch->cancelled() => 'bg-secondary',
                    $batch->finished() => 'bg-success',
                    default => 'bg-success progress-bar-striped progress-bar-animated',
                };
            @endphp

            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted">Status</dt>
                <dd class="col-sm-8">
                    @if($batch->cancelled())
                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Cancelled</span>
                    @elseif($batch->finished())
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Finished</span>
                    @else
                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Running</span>
                    @endif
                </dd>

                <dt class="col-sm-4 text-muted">Jobs Completed</dt>
                <dd class="col-sm-8">{{ $batch->processedJobs() }} / {{ $batch->totalJobs }}</dd>

                <dt class="col-sm-4 text-muted">Progress</dt>
                <dd class="col-sm-8">
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar {{ $progressBarClasses }}" style="width: {{ $progressPercent }}%;">
                            {{ round($progressPercent) }}%
                        </div>
                    </div>
                </dd>

                <dt class="col-sm-4 text-muted">Last Job</dt>
                <dd class="col-sm-8">
                    @if($rollingSchedule['lastRunAt'])
                        {{ $rollingSchedule['lastRunAt']->toDayDateTimeString() }} ({{ $rollingSchedule['lastRunAt']->diffForHumans() }})
                    @else
                        Not started yet
                    @endif
                </dd>

                <dt class="col-sm-4 text-muted">Next Job</dt>
                <dd class="col-sm-8">
                    @if($rollingSchedule['nextRunAt'])
                        {{ $rollingSchedule['nextRunAt']->toDayDateTimeString() }} ({{ $rollingSchedule['nextRunAt']->diffForHumans() }})
                    @elseif($batch->finished())
                        Completed
                    @else
                        Pending schedule
                    @endif
                </dd>

                <dt class="col-sm-4 text-muted">Started</dt>
                <dd class="col-sm-8">{{ $batch->createdAt->toDayDateTimeString() }} ({{ $batch->createdAt->diffForHumans() }})</dd>

                @if($batch->finishedAt)
                    <dt class="col-sm-4 text-muted">Finished</dt>
                    <dd class="col-sm-8">{{ $batch->finishedAt->toDayDateTimeString() }} ({{ $batch->finishedAt->diffForHumans() }})</dd>
                @endif

                @if($batch->cancelledAt)
                    <dt class="col-sm-4 text-muted">Cancelled</dt>
                    <dd class="col-sm-8 text-danger">{{ $batch->cancelledAt->toDayDateTimeString() }} ({{ $batch->cancelledAt->diffForHumans() }})</dd>
                @endif
            </dl>
        @else
            <p class="mb-0 text-muted">
                No rolling nation sync is currently scheduled. The nightly scheduler will queue it automatically.
            </p>
        @endif
    </div>
</div>
