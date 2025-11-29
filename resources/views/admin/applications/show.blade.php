@extends('layouts.admin')

@section('title', 'Application #'.$application->id)

@section('content')
    @php
        $statusValue = $application->status->value ?? (string) $application->status;
        $statusClass = match($statusValue) {
            \App\Enums\ApplicationStatus::Approved->value => 'text-bg-success',
            \App\Enums\ApplicationStatus::Denied->value => 'text-bg-danger',
            \App\Enums\ApplicationStatus::Cancelled->value => 'text-bg-secondary',
            default => 'text-bg-warning'
        };
    @endphp

    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-8">
                    <h3 class="mb-0">Application #{{ $application->id }}</h3>
                    <div class="text-muted">Nation #{{ $application->nation_id }} &mdash; {{ $application->leader_name_snapshot }}</div>
                </div>
                <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
                    <span class="badge {{ $statusClass }} px-3 py-2">{{ ucfirst(strtolower($statusValue)) }}</span>
                    @can('manage-applications')
                        @if($application->status === \App\Enums\ApplicationStatus::Pending)
                            <form action="{{ route('admin.applications.cancel', $application) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger btn-sm ms-2"
                                        onclick="return confirm('Cancel this application?')">
                                    Cancel
                                </button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Applicant</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Leader</dt>
                        <dd class="col-7">{{ $application->leader_name_snapshot }}</dd>

                        <dt class="col-5">Nation</dt>
                        <dd class="col-7">
                            <a href="https://politicsandwar.com/nation/id={{ $application->nation_id }}" target="_blank" rel="noopener">
                                #{{ $application->nation_id }}
                            </a>
                        </dd>

                        <dt class="col-5">Discord</dt>
                        <dd class="col-7">
                            {{ $application->discord_username }}
                            <div class="text-muted small">{{ $application->discord_user_id }}</div>
                        </dd>

                        <dt class="col-5">Channel</dt>
                        <dd class="col-7">{{ $application->discord_channel_id ?? '—' }}</dd>

                        <dt class="col-5">Created</dt>
                        <dd class="col-7">{{ $application->created_at?->toDayDateTimeString() ?? '—' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Status</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <strong>Current:</strong>
                            <span class="badge {{ $statusClass }}">{{ ucfirst(strtolower($statusValue)) }}</span>
                        </li>
                        <li class="mb-2">
                            <strong>Approved:</strong>
                            {{ $application->approved_at?->toDayDateTimeString() ?? '—' }}
                            @if($application->approved_by_discord_id)
                                <span class="text-muted small">by {{ $application->approved_by_discord_id }}</span>
                            @endif
                        </li>
                        <li class="mb-2">
                            <strong>Denied:</strong>
                            {{ $application->denied_at?->toDayDateTimeString() ?? '—' }}
                            @if($application->denied_by_discord_id)
                                <span class="text-muted small">by {{ $application->denied_by_discord_id }}</span>
                            @endif
                        </li>
                        <li class="mb-2">
                            <strong>Cancelled:</strong>
                            {{ $application->cancelled_at?->toDayDateTimeString() ?? '—' }}
                            @if($application->cancelled_by_discord_id)
                                <span class="text-muted small">by {{ $application->cancelled_by_discord_id }}</span>
                            @endif
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Interview Transcript</h5>
                    <span class="text-muted small">{{ $application->messages->count() }} messages</span>
                </div>
                <div class="card-body">
                    @forelse($application->messages as $message)
                        <div class="mb-3 p-3 rounded {{ $message->is_staff ? 'bg-body-secondary' : 'bg-body' }}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>{{ $message->discord_username }}</strong>
                                    <div class="text-muted small">{{ $message->discord_user_id }}</div>
                                </div>
                                <span class="text-muted small">{{ $message->sent_at?->toDayDateTimeString() ?? '—' }}</span>
                            </div>
                            @if($message->is_staff)
                                <span class="badge text-bg-info mt-2">Staff</span>
                            @endif
                            <p class="mb-0 mt-2">{!! nl2br(e($message->content)) !!}</p>
                        </div>
                    @empty
                        <div class="alert alert-info mb-0">
                            No messages have been logged for this application yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
