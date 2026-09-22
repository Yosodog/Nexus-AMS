@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\PageActivityLog> $recentActivity */
    $initialContent = is_string($page->draft) ? $page->draft : '';
    $endpoints = [
        'preview' => route('admin.customization.preview', $page),
        'draft' => route('admin.customization.draft', $page),
        'publish' => route('admin.customization.publish', $page),
        'unpublish' => route('admin.customization.unpublish', $page),
        'versions' => route('admin.customization.versions', $page),
        'restore' => route('admin.customization.restore', $page),
        'upload' => route('admin.customization.images.store'),
    ];

    $initialActivity = $recentActivity
        ->map(function (\App\Models\PageActivityLog $log): array {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->only(['id', 'name']),
            ];
        })
        ->values();

    $isSpecialPage = $page->slug === 'apply';
    $liveUrl = $isSpecialPage ? route('apply.show') : route('pages.show', ['slug' => $page->slug]);
    $editorPage = [
        'id' => $pageState['id'],
        'slug' => $pageState['slug'],
        'status' => $pageState['status'],
        'status_label' => $pageState['status_label'],
        'is_live' => $pageState['is_live'],
        'is_special' => $pageState['is_special'],
        'page_metadata' => $pageMetadata,
    ];
@endphp

@extends('layouts.admin')

@section('title', 'Customize ' . ($isSpecialPage ? 'Apply' : ($pageMetadata['title'] ?? $page->slug)))

@section('content')
    <x-header :title="'Customize Page: /' . $page->slug" separator use-h1>
        <x-slot:subtitle>
            @if($isSpecialPage)
                This is the special recruitment page. Its route, layout, and audience behavior are managed by the application.
            @else
                Use the editor to update headings, narrative copy, embeds, and media for this page.
            @endif
        </x-slot:subtitle>
        <x-slot:actions>
            <div class="w-full sm:w-72">
                <label for="customization-page-picker" class="fieldset-legend mb-0.5">Switch to another page</label>
                <select id="customization-page-picker" class="select w-full" aria-label="Select page to customize">
                    @foreach($pages as $candidate)
                        <option value="{{ route('admin.customization.edit', $candidate) }}" @selected($candidate->id === $page->id)>
                            /{{ $candidate->slug }}
                        </option>
                    @endforeach
                </select>
            </div>
        </x-slot:actions>
    </x-header>

    @if(session('status'))
        <div class="alert alert-success mb-6" role="status">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
        <div class="space-y-6">
        @unless($isSpecialPage)
            <x-card title="Page details" subtitle="These details are published together with the page content.">
                <div class="grid gap-4">
                    <div>
                        <label for="customization-page-title" class="fieldset-legend">Title</label>
                        <input
                            id="customization-page-title"
                            type="text"
                            class="input w-full"
                            value="{{ $pageMetadata['title'] ?? '' }}"
                            maxlength="255"
                            required
                        >
                    </div>
                    <div>
                        <label for="customization-page-description" class="fieldset-legend">Search description <span class="font-normal nexus-text-muted">(optional)</span></label>
                        <textarea id="customization-page-description" class="textarea w-full" rows="3" maxlength="320">{{ $pageMetadata['description'] ?? '' }}</textarea>
                    </div>
                    <div>
                        <label for="customization-page-audience" class="fieldset-legend">Audience</label>
                        <select id="customization-page-audience" class="select w-full">
                            <option value="public" @selected(($pageMetadata['audience'] ?? 'public') === 'public')>Public</option>
                            <option value="member" @selected(($pageMetadata['audience'] ?? 'public') === 'member')>Alliance members</option>
                        </select>
                        <p class="mt-1 text-sm nexus-text-muted">Member pages are available to accepted members of the primary alliance or enabled offshores.</p>
                    </div>
                </div>
            </x-card>
        @else
            <div class="alert alert-info text-sm" role="note">
                <x-icon name="o-information-circle" class="size-5" />
                <span><strong>Special page:</strong> /apply keeps its existing recruitment layout and behavior. Its title, slug, and audience cannot be changed here.</span>
            </div>
        @endunless

        <x-card title="Editor" class="min-w-0">
            <x-slot:menu>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline btn-sm" id="customization-preview">
                        <x-icon name="o-eye" class="size-4" />
                        Preview
                    </button>
                    <a id="customization-view-live" href="{{ $liveUrl }}" target="_blank" rel="noopener noreferrer" @class(['btn btn-outline btn-sm', 'hidden' => ! $isSpecialPage && ! ($pageState['is_live'] ?? false)])>
                        <x-icon name="o-arrow-top-right-on-square" class="size-4" />
                        View live page
                    </a>
                    <button type="button" class="btn btn-outline btn-primary btn-sm" id="customization-save">
                        <x-icon name="o-document-arrow-down" class="size-4" />
                        Save Draft
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="customization-publish">
                        <x-icon name="o-signal" class="size-4" />
                        Publish
                    </button>
                    @unless($isSpecialPage)
                        <button type="button" id="customization-unpublish" @class(['btn btn-outline btn-error btn-sm', 'hidden' => ! ($pageState['is_live'] ?? false)])>
                            <x-icon name="o-eye-slash" class="size-4" />
                            Unpublish
                        </button>
                    @endunless
                    <button type="button" class="btn btn-outline btn-neutral btn-sm" id="customization-versions">
                        <x-icon name="o-clock" class="size-4" />
                        Versions
                    </button>
                </div>
            </x-slot:menu>

            <div
                id="customization-editor"
                class="rounded-box border border-base-300 bg-base-200/40 p-4"
                data-endpoints='@json($endpoints)'
                data-page='@json($editorPage)'
                data-csrf="{{ csrf_token() }}"
                data-initial-activity='@json($initialActivity)'
            >
                <textarea
                    id="customization-editor-input"
                    class="textarea js-jodit w-full"
                    data-editor-input="true"
                    data-editor-height="640"
                    data-editor-upload-url="{{ $endpoints['upload'] }}"
                    data-editor-csrf="{{ csrf_token() }}"
                    rows="14"
                >{{ $initialContent }}</textarea>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm nexus-text-muted">
                <span class="badge badge-ghost" id="customization-preview-status">Awaiting preview</span>
                <span>Preview opens the current editor content in its page layout without publishing.</span>
            </div>
        </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Audit Summary">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                    <dt class="nexus-text-muted">Current status</dt>
                    <dd class="font-semibold" id="customization-status">{{ $pageState['status_label'] ?? ucfirst($page->status) }}</dd>

                    <dt class="nexus-text-muted">Last draft</dt>
                    <dd id="customization-last-draft">
                        @if($latestDraft)
                            <div>{{ $latestDraft->created_at?->diffForHumans() ?? 'Recently' }}</div>
                            <div class="nexus-text-muted">by {{ $latestDraft->user?->name ?? 'System' }}</div>
                        @else
                            <span class="nexus-text-muted">No drafts yet</span>
                        @endif
                    </dd>

                    <dt class="nexus-text-muted">Last publish</dt>
                    <dd id="customization-last-publish">
                        @if($latestPublished)
                            <div>{{ $latestPublished->published_at?->diffForHumans() ?? 'Recently' }}</div>
                            <div class="nexus-text-muted">by {{ $latestPublished->user?->name ?? 'System' }}</div>
                        @else
                            <span class="nexus-text-muted">Never published</span>
                        @endif
                    </dd>
                </dl>
            </x-card>

            <x-card title="Recent Activity" :subtitle="$recentActivity->count() . ' entries'">
                <ul class="space-y-3 text-sm" id="customization-activity-list">
                    @forelse($recentActivity as $log)
                        <li class="rounded-box border border-base-300 bg-base-200/30 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide nexus-text-muted">{{ \Illuminate\Support\Str::headline($log->action) }}</div>
                            <div>{{ $log->created_at?->diffForHumans() ?? 'Recently' }} | {{ $log->user?->name ?? 'System' }}</div>
                            @if(!empty($log->metadata))
                                <code class="mt-1 block overflow-x-auto">{{ json_encode($log->metadata) }}</code>
                            @endif
                        </li>
                    @empty
                        <li class="nexus-text-muted">No activity has been recorded yet.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
    </div>

    <dialog id="customization-preview-modal" class="modal">
        <div class="modal-box flex h-[min(92vh,64rem)] w-[min(96vw,96rem)] max-w-none flex-col overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-300 px-5 py-3">
                <div>
                    <h2 class="font-display text-xl font-bold">Page preview</h2>
                    <p class="text-sm nexus-text-muted">Current editor content. Links and interactive controls are disabled in preview.</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="join" aria-label="Preview width">
                        <button type="button" class="btn btn-sm join-item btn-active" data-preview-width="desktop">Desktop</button>
                        <button type="button" class="btn btn-sm join-item" data-preview-width="mobile">Mobile</button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" id="customization-preview-close">Close</button>
                </div>
            </div>
            <div class="flex min-h-0 flex-1 justify-center overflow-auto bg-base-200 p-3">
                <iframe id="customization-preview-frame" title="Page preview" sandbox="allow-same-origin" class="h-full w-full max-w-full border border-base-300 bg-base-100 shadow-lg"></iframe>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button type="submit">Close preview</button></form>
    </dialog>

    <x-modal id="customization-version-modal" title="Version History" separator box-class="max-w-5xl">
        <div class="space-y-4">
            <div class="alert alert-info hidden text-sm" role="alert" id="customization-versions-alert">
                Loading version history...
            </div>
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm" data-sortable="false">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Page details</th>
                        <th class="text-right" data-sortable="false">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="customization-versions-table"></tbody>
                </table>
            </div>
        </div>

        <x-slot:actions>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('customization-version-modal').close()">Close</button>
        </x-slot:actions>
    </x-modal>
@endsection

@push('scripts')
    @vite('resources/js/jodit.js')
    @vite('resources/js/customization/editor.js')
@endpush
