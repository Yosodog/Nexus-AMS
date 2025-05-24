@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-6">
                    <h3 class="mb-0">Admin Settings</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Sync Settings --}}
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Nation Sync</h5>

                    @if($nationBatch && !$nationBatch->finished())
                        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Running...
                        </button>
                    @else
                        <form method="POST" action="{{ route('admin.settings.sync.run') }}">
                            @csrf
                            <button class="btn btn-sm btn-primary" type="submit">Run Nation Sync</button>
                        </form>
                    @endif
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Last Ran</dt>
                        <dd class="col-sm-9">{{ $nationBatch->finishedAt ?? 'Never' }}</dd>

                        @if($nationBatch)
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                {{ $nationBatch->finished() ? 'Finished' : 'Running' }}
                                @if($nationBatch->cancelled())
                                    <span class="text-danger ms-2">(Cancelled)</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Jobs Completed</dt>
                            <dd class="col-sm-9">{{ $nationBatch->processedJobs() }}
                                / {{ $nationBatch->totalJobs }}</dd>

                            <dt class="col-sm-3">Progress</dt>
                            <dd class="col-sm-9">
                                <div class="progress" style="height: 24px;">
                                    <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                                         style="width: {{ ($nationBatch->progress()) }}%">
                                        {{ round(($nationBatch->progress())) }}%
                                    </div>
                                </div>
                            </dd>

                            <dt class="col-sm-3">Failed Jobs</dt>
                            <dd class="col-sm-9 text-danger">{{ $nationBatch->failedJobs }}</dd>

                            <dt class="col-sm-3">Started</dt>
                            <dd class="col-sm-9">{{ $nationBatch->createdAt->diffForHumans() }}</dd>

                            @if($nationBatch->finishedAt)
                                <dt class="col-sm-3">Finished</dt>
                                <dd class="col-sm-9">{{ $nationBatch->finishedAt->diffForHumans() }}</dd>
                            @endif

                            @if($nationBatch->cancelledAt)
                                <dt class="col-sm-3">Cancelled</dt>
                                <dd class="col-sm-9 text-danger">{{ $nationBatch->cancelledAt->diffForHumans() }}</dd>
                            @endif
                        @else
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9 text-muted">Idle</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- Alliance Sync --}}
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Alliance Sync</h5>

                    @if($allianceBatch && !$allianceBatch->finished())
                        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Running...
                        </button>
                    @else
                        <form method="POST" action="{{ route('admin.settings.sync.alliances') }}">
                            @csrf
                            <button class="btn btn-sm btn-primary" type="submit">Run Alliance Sync</button>
                        </form>
                    @endif
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Last Ran</dt>
                        <dd class="col-sm-9">{{ $allianceBatch->finishedAt ?? 'Never' }}</dd>

                        @if($allianceBatch)
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                {{ $allianceBatch->finished() ? 'Finished' : 'Running' }}
                                @if($allianceBatch->cancelled())
                                    <span class="text-danger ms-2">(Cancelled)</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Jobs Completed</dt>
                            <dd class="col-sm-9">{{ $allianceBatch->processedJobs() }}
                                / {{ $allianceBatch->totalJobs }}</dd>

                            <dt class="col-sm-3">Progress</dt>
                            <dd class="col-sm-9">
                                <div class="progress" style="height: 24px;">
                                    <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                                         style="width: {{ $allianceBatch->progress() }}%">
                                        {{ round($allianceBatch->progress()) }}%
                                    </div>
                                </div>
                            </dd>

                            <dt class="col-sm-3">Failed Jobs</dt>
                            <dd class="col-sm-9 text-danger">{{ $allianceBatch->failedJobs }}</dd>

                            <dt class="col-sm-3">Started</dt>
                            <dd class="col-sm-9">{{ $allianceBatch->createdAt->diffForHumans() }}</dd>

                            @if($allianceBatch->finishedAt)
                                <dt class="col-sm-3">Finished</dt>
                                <dd class="col-sm-9">{{ $allianceBatch->finishedAt->diffForHumans() }}</dd>
                            @endif

                            @if($allianceBatch->cancelledAt)
                                <dt class="col-sm-3">Cancelled</dt>
                                <dd class="col-sm-9 text-danger">{{ $allianceBatch->cancelledAt->diffForHumans() }}</dd>
                            @endif
                        @else
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9 text-muted">Idle</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- War Sync --}}
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">War Sync</h5>

                    @if($warBatch && !$warBatch->finished())
                        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Running...
                        </button>
                    @else
                        <form method="POST" action="{{ route('admin.settings.sync.wars') }}">
                            @csrf
                            <button class="btn btn-sm btn-primary" type="submit">Run War Sync</button>
                        </form>
                    @endif
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Last Ran</dt>
                        <dd class="col-sm-9">{{ $warBatch->finishedAt ?? 'Never' }}</dd>

                        @if($warBatch)
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                {{ $warBatch->finished() ? 'Finished' : 'Running' }}
                                @if($warBatch->cancelled())
                                    <span class="text-danger ms-2">(Cancelled)</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Jobs Completed</dt>
                            <dd class="col-sm-9">{{ $warBatch->processedJobs() }} / {{ $warBatch->totalJobs }}</dd>

                            <dt class="col-sm-3">Progress</dt>
                            <dd class="col-sm-9">
                                <div class="progress" style="height: 24px;">
                                    <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                                         style="width: {{ $warBatch->progress() }}%">
                                        {{ round($warBatch->progress()) }}%
                                    </div>
                                </div>
                            </dd>

                            <dt class="col-sm-3">Failed Jobs</dt>
                            <dd class="col-sm-9 text-danger">{{ $warBatch->failedJobs }}</dd>

                            <dt class="col-sm-3">Started</dt>
                            <dd class="col-sm-9">{{ $warBatch->createdAt->diffForHumans() }}</dd>

                            @if($warBatch->finishedAt)
                                <dt class="col-sm-3">Finished</dt>
                                <dd class="col-sm-9">{{ $warBatch->finishedAt->diffForHumans() }}</dd>
                            @endif

                            @if($warBatch->cancelledAt)
                                <dt class="col-sm-3">Cancelled</dt>
                                <dd class="col-sm-9 text-danger">{{ $warBatch->cancelledAt->diffForHumans() }}</dd>
                            @endif
                        @else
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9 text-muted">Idle</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection