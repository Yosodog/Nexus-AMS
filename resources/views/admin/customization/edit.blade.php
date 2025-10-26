@php
    $initialBlocks = $page->draft ?? [];
    $endpoints = [
        'preview' => route('admin.customization.preview', $page),
        'draft' => route('admin.customization.draft', $page),
        'publish' => route('admin.customization.publish', $page),
        'versions' => route('admin.customization.versions', $page),
        'restore' => route('admin.customization.restore', $page),
        'upload' => route('admin.customization.images.store'),
    ];
@endphp

@extends('layouts.admin')

@section('title', 'Customize ' . $page->slug)

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <h3 class="mb-0">Customize Page: /{{ $page->slug }}</h3>
                    <p class="text-muted small mb-0">Use the editor to update headings, narrative copy, embeds, and media for this page.</p>
                </div>
                <div class="col-lg-4">
                    <label for="customization-page-picker" class="form-label small mb-1">Switch to another page</label>
                    <select id="customization-page-picker" class="form-select"
                            aria-label="Select page to customize">
                        @foreach($pages as $candidate)
                            <option value="{{ route('admin.customization.edit', $candidate) }}" @selected($candidate->id === $page->id)>
                                /{{ $candidate->slug }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0">Editor</h5>
                    <div class="btn-group" role="group" aria-label="Editor actions">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="customization-preview">
                            <i class="bi bi-eye me-1"></i> Preview
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="customization-save">
                            <i class="bi bi-floppy me-1"></i> Save Draft
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="customization-publish">
                            <i class="bi bi-broadcast me-1"></i> Publish
                        </button>
                        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#customization-version-modal" id="customization-versions">
                            <i class="bi bi-clock-history me-1"></i> Versions
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="customization-editor"
                         class="bg-body-secondary rounded border p-3"
                         data-endpoints='@json($endpoints)'
                         data-blocks='@json($initialBlocks)'
                         data-page='@json(['id' => $page->id, 'slug' => $page->slug, 'status' => $page->status])'
                         data-csrf="{{ csrf_token() }}"
                         data-initial-activity='@json($recentActivity->map(fn($log) => [
                             'id' => $log->id,
                             'action' => $log->action,
                             'metadata' => $log->metadata,
                             'created_at' => $log->created_at?->toIso8601String(),
                             'user' => $log->user?->only(['id', 'name']),
                         ]))'></div>

                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Live Preview</h6>
                            <span class="badge text-bg-light" id="customization-preview-status">Awaiting preview</span>
                        </div>
                        <div id="customization-preview-pane" class="border rounded p-3 bg-white shadow-sm" style="min-height: 200px;">
                            <p class="text-muted mb-0">Use the Preview button to render the current draft without publishing.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Audit Summary</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Current status</dt>
                        <dd class="col-7 fw-semibold" id="customization-status">{{ ucfirst($page->status) }}</dd>

                        <dt class="col-5 text-muted">Last draft</dt>
                        <dd class="col-7" id="customization-last-draft">
                            @if($latestDraft)
                                <div>{{ $latestDraft->created_at?->diffForHumans() ?? 'Recently' }}</div>
                                <div class="text-muted">by {{ $latestDraft->user?->name ?? 'System' }}</div>
                            @else
                                <span class="text-muted">No drafts yet</span>
                            @endif
                        </dd>

                        <dt class="col-5 text-muted">Last publish</dt>
                        <dd class="col-7" id="customization-last-publish">
                            @if($latestPublished)
                                <div>{{ $latestPublished->published_at?->diffForHumans() ?? 'Recently' }}</div>
                                <div class="text-muted">by {{ $latestPublished->user?->name ?? 'System' }}</div>
                            @else
                                <span class="text-muted">Never published</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Activity</h5>
                    <span class="badge text-bg-light">{{ $recentActivity->count() }}</span>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small" id="customization-activity-list">
                        @forelse($recentActivity as $log)
                            <li class="mb-3">
                                <div class="fw-semibold text-uppercase small text-muted">{{ \Illuminate\Support\Str::headline($log->action) }}</div>
                                <div>{{ $log->created_at?->diffForHumans() ?? 'Recently' }} &mdash; {{ $log->user?->name ?? 'System' }}</div>
                                @if(!empty($log->metadata))
                                    <code class="d-block mt-1">{{ json_encode($log->metadata) }}</code>
                                @endif
                            </li>
                        @empty
                            <li class="text-muted">No activity has been recorded yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="customization-version-modal" tabindex="-1" aria-labelledby="customization-version-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customization-version-modal-label">Version History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small" role="alert" id="customization-versions-alert">
                        Loading version history&hellip;
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody id="customization-versions-table"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @vite('resources/js/customization/editor.js')
@endpush
