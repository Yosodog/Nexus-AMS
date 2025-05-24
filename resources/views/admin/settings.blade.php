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

                    @if($batch && !$batch->finished())
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
                        <dd class="col-sm-9">{{ $batch->finishedAt ?? 'Never' }}</dd>

                        @if($batch)
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                {{ $batch->finished() ? 'Finished' : 'Running' }}
                                @if($batch->cancelled())
                                    <span class="text-danger ms-2">(Cancelled)</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Jobs Completed</dt>
                            <dd class="col-sm-9">{{ $batch->processedJobs() }} / {{ $batch->totalJobs }}</dd>

                            <dt class="col-sm-3">Progress</dt>
                            <dd class="col-sm-9">
                                <div class="progress" style="height: 24px;">
                                    <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                                         style="width: {{ ($batch->progress()) }}%">
                                        {{ round(($batch->progress())) }}%
                                    </div>
                                </div>
                            </dd>

                            <dt class="col-sm-3">Failed Jobs</dt>
                            <dd class="col-sm-9 text-danger">{{ $batch->failedJobs }}</dd>

                            <dt class="col-sm-3">Started</dt>
                            <dd class="col-sm-9">{{ $batch->createdAt->diffForHumans() }}</dd>

                            @if($batch->finishedAt)
                                <dt class="col-sm-3">Finished</dt>
                                <dd class="col-sm-9">{{ $batch->finishedAt->diffForHumans() }}</dd>
                            @endif

                            @if($batch->cancelledAt)
                                <dt class="col-sm-3">Cancelled</dt>
                                <dd class="col-sm-9 text-danger">{{ $batch->cancelledAt->diffForHumans() }}</dd>
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

    {{-- Placeholder for other sections --}}
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">Alliance Sync (Coming Soon)</div>
                <div class="card-body text-muted">
                    Future support for alliance syncing and controls.
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">War Sync (Coming Soon)</div>
                <div class="card-body text-muted">
                    Future support for war data syncing and visibility controls.
                </div>
            </div>
        </div>
    </div>
@endsection