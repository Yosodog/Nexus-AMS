@extends('layouts.admin')

@section('title', 'Audit Logs')

@section('content')
    @php
        $pageLogs = $logs->getCollection();
        $errorCount = $pageLogs->where('severity', 'error')->count();
        $warningCount = $pageLogs->where('severity', 'warning')->count();
        $successCount = $pageLogs->where('outcome', 'success')->count();
    @endphp

    <x-header title="Audit Logs" separator>
        <x-slot:subtitle>Filter security and workflow events, then inspect request context without leaving the table.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-ghost btn-sm">Reset Filters</a>
        </x-slot:actions>
    </x-header>

    <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-stat title="Visible Events" :value="number_format($logs->total())" icon="o-rectangle-stack" color="text-primary" description="Matches for the current filter set" />
        <x-stat title="This Page" :value="number_format($pageLogs->count())" icon="o-document-text" color="text-info" description="Rows currently rendered" />
        <x-stat title="Warnings" :value="number_format($warningCount)" icon="o-exclamation-triangle" color="text-warning" description="Warning-severity events on this page" />
        <x-stat title="Errors" :value="number_format($errorCount)" icon="o-shield-exclamation" color="text-error" description="Error-severity events on this page" />
    </div>

    <x-card class="mb-6">
        <x-slot:title>
            <div>
                Filters
                <div class="text-sm font-normal text-base-content/60">Tighten the result set by event type, actor, request metadata, or free-text search.</div>
            </div>
        </x-slot:title>

        <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="form-label" for="categoryFilter">Category</label>
                <select class="select select-bordered w-full" id="categoryFilter" name="category">
                    <option value="">All</option>
                    @foreach($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="form-label" for="outcomeFilter">Outcome</label>
                <select class="select select-bordered w-full" id="outcomeFilter" name="outcome">
                    <option value="">All</option>
                    @foreach($outcomes as $outcome)
                        <option value="{{ $outcome }}" @selected($filters['outcome'] === $outcome)>{{ $outcome }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="form-label" for="severityFilter">Severity</label>
                <select class="select select-bordered w-full" id="severityFilter" name="severity">
                    <option value="">All</option>
                    @foreach($severities as $severity)
                        <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $severity }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="form-label" for="actorTypeFilter">Actor Type</label>
                <select class="select select-bordered w-full" id="actorTypeFilter" name="actor_type">
                    <option value="">All</option>
                    @foreach($actorTypes as $actorType)
                        <option value="{{ $actorType }}" @selected($filters['actor_type'] === $actorType)>{{ $actorType }}</option>
                    @endforeach
                </select>
            </div>

            <x-input label="Action" id="actionFilter" name="action" :value="$filters['action']" placeholder="run, update, approve" />
            <x-input label="Actor ID" id="actorIdFilter" name="actor_id" :value="$filters['actor_id']" placeholder="User or system actor id" />
            <x-input label="Subject Type" id="subjectTypeFilter" name="subject_type" :value="$filters['subject_type']" placeholder="Model or workflow type" />
            <x-input label="Subject ID" id="subjectIdFilter" name="subject_id" :value="$filters['subject_id']" placeholder="Target record id" />
            <x-input label="Request ID" id="requestIdFilter" name="request_id" :value="$filters['request_id']" placeholder="Correlate backend events" />
            <x-input label="IP Address" id="ipFilter" name="ip" :value="$filters['ip']" placeholder="IPv4 or IPv6" />

            <div class="xl:col-span-2">
                <label class="form-label" for="searchFilter">Search</label>
                <input class="input input-bordered w-full" id="searchFilter" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Message, actor name, request id, or action">
            </div>

            <div class="flex items-end gap-2 xl:col-span-4 xl:justify-end">
                <button class="btn btn-primary btn-sm" type="submit">Apply Filters</button>
                <a class="btn btn-ghost btn-sm" href="{{ route('admin.audit-logs.index') }}">Reset</a>
            </div>
        </form>
    </x-card>

    <x-card>
        <x-slot:title>
            <div>
                Event Stream
                <div class="text-sm font-normal text-base-content/60">Sorted chronologically. Click table headers to sort locally.</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <x-badge :value="number_format($successCount) . ' success events on page'" class="badge-success badge-sm" />
        </x-slot:menu>

        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra table-sm align-middle">
                <thead>
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
                        <th data-sortable="false">Context</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $outcomeClass = match ($log->outcome) {
                                'success' => 'badge-success',
                                'warning' => 'badge-warning',
                                'failed', 'failure', 'denied' => 'badge-error',
                                default => 'badge-ghost',
                            };
                            $severityClass = match ($log->severity) {
                                'error', 'critical' => 'badge-error',
                                'warning' => 'badge-warning',
                                'info' => 'badge-info',
                                default => 'badge-ghost',
                            };
                        @endphp
                        <tr>
                            <td class="text-nowrap">
                                <div class="font-medium">{{ $log->occurred_at?->format('Y-m-d H:i:s') ?? '—' }}</div>
                                <div class="text-xs text-base-content/50">{{ $log->occurred_at?->diffForHumans() ?? 'Unknown' }}</div>
                            </td>
                            <td>
                                <x-badge :value="$log->category" class="badge-ghost badge-sm" />
                            </td>
                            <td>
                                <div class="font-medium text-base-content">{{ $log->action }}</div>
                            </td>
                            <td>
                                <x-badge :value="$log->outcome" class="{{ $outcomeClass }} badge-sm" />
                            </td>
                            <td>
                                <x-badge :value="$log->severity" class="{{ $severityClass }} badge-sm" />
                            </td>
                            <td>
                                <div class="text-xs uppercase tracking-wide text-base-content/50">{{ $log->actor_type ?? 'Unknown' }}</div>
                                <div class="font-medium text-base-content">{{ $log->actor_name ?? '—' }}</div>
                                <div class="text-xs text-base-content/50">ID: {{ $log->actor_id ?? '—' }}</div>
                            </td>
                            <td>
                                <div class="text-xs uppercase tracking-wide text-base-content/50">{{ $log->subject_type ?? '—' }}</div>
                                <div class="text-xs text-base-content/50">ID: {{ $log->subject_id ?? '—' }}</div>
                            </td>
                            <td>
                                <div class="text-xs text-base-content/50">Req: {{ $log->request_id ?? '—' }}</div>
                                <div class="text-xs text-base-content/50">IP: {{ $log->ip ?? '—' }}</div>
                            </td>
                            <td class="min-w-[18rem]">
                                <div class="text-sm text-base-content">{{ $log->message ?? '—' }}</div>
                            </td>
                            <td class="min-w-[16rem]">
                                @if($log->context)
                                    <details class="rounded-box bg-base-200/70 p-3">
                                        <summary class="cursor-pointer text-sm font-medium text-base-content/70">View JSON</summary>
                                        <pre class="mt-3 overflow-x-auto rounded-xl bg-base-300/50 p-3 text-xs text-base-content">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @else
                                    <span class="text-sm text-base-content/50">No context</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-8 text-center text-base-content/50">No audit logs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <span class="text-sm text-base-content/60">Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
            {{ $logs->onEachSide(1)->links() }}
        </div>
    </x-card>
@endsection
