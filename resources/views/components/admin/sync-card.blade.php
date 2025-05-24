<div class="card shadow-sm mb-4 h-100">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0 text-primary fw-semibold">{{ $title }}</h5>

        @if($batch && !$batch->finished())
            <div class="ms-auto">
                <button class="btn btn-sm btn-outline-secondary" type="button" disabled>
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Syncing...
                </button>
            </div>
        @else
            <form method="POST" action="{{ $route }}" class="ms-auto">
                @csrf
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-arrow-repeat me-1"></i> Run Sync
                </button>
            </form>
        @endif
    </div>
    <div class="card-body small">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Last Ran</dt>
            <dd class="col-sm-8">{{ $batch?->finishedAt ?? 'Never' }}</dd>

            <dt class="col-sm-4 text-muted">Status</dt>
            <dd class="col-sm-8">
                @if($batch)
                    @if($batch->finished())
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Finished</span>
                    @elseif($batch->cancelled())
                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Cancelled</span>
                    @else
                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Running</span>
                    @endif
                @else
                    <span class="text-muted">Idle</span>
                @endif
            </dd>

            @if($batch)
                <dt class="col-sm-4 text-muted">Jobs Completed</dt>
                <dd class="col-sm-8">{{ $batch->processedJobs() }} / {{ $batch->totalJobs }}</dd>

                <dt class="col-sm-4 text-muted">Progress</dt>
                <dd class="col-sm-8">
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                             style="width: {{ $batch->progress() }}%">
                            {{ round($batch->progress()) }}%
                        </div>
                    </div>
                </dd>

                <dt class="col-sm-4 text-muted">Failed Jobs</dt>
                <dd class="col-sm-8 text-danger fw-semibold">
                    {{ $batch->failedJobs }}
                </dd>

                <dt class="col-sm-4 text-muted">Started</dt>
                <dd class="col-sm-8">{{ $batch->createdAt->diffForHumans() }}</dd>

                @if($batch->finishedAt)
                    <dt class="col-sm-4 text-muted">Finished</dt>
                    <dd class="col-sm-8">{{ $batch->finishedAt->diffForHumans() }}</dd>
                @endif

                @if($batch->cancelledAt)
                    <dt class="col-sm-4 text-muted">Cancelled</dt>
                    <dd class="col-sm-8 text-danger">{{ $batch->cancelledAt->diffForHumans() }}</dd>
                @endif
            @endif
        </dl>
    </div>
</div>