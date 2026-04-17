@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\PageActivityLog> $recentActivity */
    $initialContent = is_string($page->draft) ? $page->draft : '';
    $endpoints = [
        'preview' => route('admin.customization.preview', $page),
        'draft' => route('admin.customization.draft', $page),
        'publish' => route('admin.customization.publish', $page),
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
@endphp

@extends('layouts.admin')

@section('title', 'Customize ' . $page->slug)

@section('content')
    <x-header :title="'Customize Page: /' . $page->slug" separator>
        <x-slot:subtitle>Use the editor to update headings, narrative copy, embeds, and media for this page.</x-slot:subtitle>
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

    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
        <x-card title="Editor" class="min-w-0">
            <x-slot:menu>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline btn-sm" id="customization-preview">
                        <x-icon name="o-eye" class="size-4" />
                        Preview
                    </button>
                    <button type="button" class="btn btn-outline btn-primary btn-sm" id="customization-save">
                        <x-icon name="o-document-arrow-down" class="size-4" />
                        Save Draft
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="customization-publish">
                        <x-icon name="o-signal" class="size-4" />
                        Publish
                    </button>
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
                data-page='@json(['id' => $page->id, 'slug' => $page->slug, 'status' => $page->status])'
                data-csrf="{{ csrf_token() }}"
                data-initial-activity='@json($initialActivity)'
            >
                <textarea
                    id="customization-editor-input"
                    class="textarea js-ckeditor w-full"
                    data-editor-input="true"
                    rows="14"
                >{{ $initialContent }}</textarea>
            </div>

            <div class="mt-6">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h6 class="font-semibold">Live Preview</h6>
                    <span class="badge badge-ghost" id="customization-preview-status">Awaiting preview</span>
                </div>
                <div id="customization-preview-pane" class="min-h-[200px] rounded-box border border-base-300 bg-base-100 p-4 shadow-sm">
                    <p class="mb-0 text-base-content/50">Use the Preview button to render the current draft without publishing.</p>
                </div>
            </div>
        </x-card>

        <div class="space-y-6">
            <x-card title="Audit Summary">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                    <dt class="text-base-content/50">Current status</dt>
                    <dd class="font-semibold" id="customization-status">{{ ucfirst($page->status) }}</dd>

                    <dt class="text-base-content/50">Last draft</dt>
                    <dd id="customization-last-draft">
                        @if($latestDraft)
                            <div>{{ $latestDraft->created_at?->diffForHumans() ?? 'Recently' }}</div>
                            <div class="text-base-content/50">by {{ $latestDraft->user?->name ?? 'System' }}</div>
                        @else
                            <span class="text-base-content/50">No drafts yet</span>
                        @endif
                    </dd>

                    <dt class="text-base-content/50">Last publish</dt>
                    <dd id="customization-last-publish">
                        @if($latestPublished)
                            <div>{{ $latestPublished->published_at?->diffForHumans() ?? 'Recently' }}</div>
                            <div class="text-base-content/50">by {{ $latestPublished->user?->name ?? 'System' }}</div>
                        @else
                            <span class="text-base-content/50">Never published</span>
                        @endif
                    </dd>
                </dl>
            </x-card>

            <x-card title="Recent Activity" :subtitle="$recentActivity->count() . ' entries'">
                <ul class="space-y-3 text-sm" id="customization-activity-list">
                    @forelse($recentActivity as $log)
                        <li class="rounded-box border border-base-300 bg-base-200/30 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-base-content/55">{{ \Illuminate\Support\Str::headline($log->action) }}</div>
                            <div>{{ $log->created_at?->diffForHumans() ?? 'Recently' }} | {{ $log->user?->name ?? 'System' }}</div>
                            @if(!empty($log->metadata))
                                <code class="mt-1 block overflow-x-auto">{{ json_encode($log->metadata) }}</code>
                            @endif
                        </li>
                    @empty
                        <li class="text-base-content/50">No activity has been recorded yet.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
    </div>

    <x-modal id="customization-version-modal" title="Version History" separator box-class="max-w-5xl">
        <div class="space-y-4">
            <div class="alert alert-info hidden text-sm" role="alert" id="customization-versions-alert">
                Loading version history...
            </div>
            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra table-sm">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th class="text-right">Actions</th>
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
    @vite('resources/js/ckeditor.js')
    @vite('resources/js/customization/editor.js')
@endpush
