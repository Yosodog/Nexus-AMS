@extends('layouts.admin')

@section('title', 'Custom Page Management')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h3 class="mb-0">Custom Page Management</h3>
                    <p class="text-muted small mb-0">Review existing custom pages and open them in the editor to update content.</p>
                </div>
                <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                    @if($pages->isNotEmpty())
                        <a href="{{ route('admin.customization.edit', $pages->first()) }}" class="btn btn-primary">
                            <i class="bi bi-pencil-square me-1"></i> Open Editor
                        </a>
                    @else
                        <button class="btn btn-primary" type="button" disabled>
                            <i class="bi bi-pencil-square me-1"></i> No pages yet
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Managed Pages</h5>
                    <span class="badge text-bg-secondary">{{ $pages->count() }} total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Slug</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($pages as $page)
                                @php
                                    $publishedAt = optional($page->latestPublishedVersion)->published_at;
                                @endphp
                                <tr>
                                    <td class="fw-semibold">/{{ $page->slug }}</td>
                                    <td>
                                        @if($page->status === \App\Models\Page::STATUS_PUBLISHED)
                                            <span class="badge text-bg-success">Published</span>
                                        @else
                                            <span class="badge text-bg-warning text-dark">Draft</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="small text-muted">
                                            Updated {{ $page->updated_at?->diffForHumans() ?? 'recently' }}
                                        </div>
                                        @if($publishedAt)
                                            <div class="small">Published {{ $publishedAt->diffForHumans() }}</div>
                                        @else
                                            <div class="small text-muted">Never published</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.customization.edit', $page) }}" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">No custom pages have been configured yet.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
