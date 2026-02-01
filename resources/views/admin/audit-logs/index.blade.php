@extends('layouts.admin')

@section('title', 'Audit Logs')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-sm-6">
                    <h3 class="mb-0">Audit Logs</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <strong>Filters</strong>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="categoryFilter">Category</label>
                        <select class="form-select" id="categoryFilter" name="category">
                            <option value="">All</option>
                            @foreach($categories as $category)
                                <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="outcomeFilter">Outcome</label>
                        <select class="form-select" id="outcomeFilter" name="outcome">
                            <option value="">All</option>
                            @foreach($outcomes as $outcome)
                                <option value="{{ $outcome }}" @selected($filters['outcome'] === $outcome)>{{ $outcome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="severityFilter">Severity</label>
                        <select class="form-select" id="severityFilter" name="severity">
                            <option value="">All</option>
                            @foreach($severities as $severity)
                                <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $severity }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="actorTypeFilter">Actor Type</label>
                        <select class="form-select" id="actorTypeFilter" name="actor_type">
                            <option value="">All</option>
                            @foreach($actorTypes as $actorType)
                                <option value="{{ $actorType }}" @selected($filters['actor_type'] === $actorType)>{{ $actorType }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="actionFilter">Action</label>
                        <input class="form-control" id="actionFilter" type="text" name="action" value="{{ $filters['action'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="actorIdFilter">Actor ID</label>
                        <input class="form-control" id="actorIdFilter" type="text" name="actor_id" value="{{ $filters['actor_id'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="subjectTypeFilter">Subject Type</label>
                        <input class="form-control" id="subjectTypeFilter" type="text" name="subject_type" value="{{ $filters['subject_type'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="subjectIdFilter">Subject ID</label>
                        <input class="form-control" id="subjectIdFilter" type="text" name="subject_id" value="{{ $filters['subject_id'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="requestIdFilter">Request ID</label>
                        <input class="form-control" id="requestIdFilter" type="text" name="request_id" value="{{ $filters['request_id'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="ipFilter">IP Address</label>
                        <input class="form-control" id="ipFilter" type="text" name="ip" value="{{ $filters['ip'] }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="searchFilter">Search</label>
                        <input class="form-control" id="searchFilter" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Message, actor name, or action">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Apply Filters</button>
                        <a class="btn btn-outline-secondary" href="{{ route('admin.audit-logs.index') }}">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Occurred</th>
                                <th>Category</th>
                                <th>Action</th>
                                <th>Outcome</th>
                                <th>Severity</th>
                                <th>Actor</th>
                                <th>Subject</th>
                                <th>Request</th>
                                <th>Message</th>
                                <th>Context</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logs as $log)
                                <tr>
                                    <td class="text-nowrap">{{ $log->occurred_at?->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $log->category }}</td>
                                    <td>{{ $log->action }}</td>
                                    <td>{{ $log->outcome }}</td>
                                    <td>{{ $log->severity }}</td>
                                    <td>
                                        <div class="small text-muted">{{ $log->actor_type }}</div>
                                        <div>{{ $log->actor_name ?? '—' }}</div>
                                        <div class="small text-muted">ID: {{ $log->actor_id ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="small text-muted">{{ $log->subject_type ?? '—' }}</div>
                                        <div class="small text-muted">ID: {{ $log->subject_id ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="small text-muted">Req: {{ $log->request_id ?? '—' }}</div>
                                        <div class="small text-muted">IP: {{ $log->ip ?? '—' }}</div>
                                    </td>
                                    <td>{{ $log->message ?? '—' }}</td>
                                    <td style="min-width: 220px;">
                                        @if($log->context)
                                            <details>
                                                <summary class="small text-muted">View</summary>
                                                <pre class="small bg-light border rounded p-2 mt-2 mb-0">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No audit logs found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3">
            {{ $logs->links() }}
        </div>
    </div>
@endsection
