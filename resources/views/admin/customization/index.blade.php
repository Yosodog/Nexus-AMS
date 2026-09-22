@extends('layouts.admin')

@section('title', 'Custom Page Management')

@section('content')
    <x-header title="Custom Page Management" separator use-h1>
        <x-slot:subtitle>Review existing custom pages and open them in the editor to update content.</x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.customization.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="o-plus" class="size-4" />
                Create page
            </a>
        </x-slot:actions>
    </x-header>

    <x-card title="Managed pages" :subtitle="$pages->count() . ' total'">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra" data-sortable="true">
                <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Audience</th>
                    <th>Status</th>
                    <th data-sortable="false">Last Updated</th>
                    <th class="text-right" data-sortable="false">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($pages as $page)
                    @php
                        $publishedAt = optional($page->latestPublishedVersion)->published_at;
                        $state = $pageStates[$page->id] ?? [];
                        $metadata = $state['page_metadata'] ?? [];
                        $statusLabel = $state['status_label'] ?? 'Never published';
                        $isSpecial = $state['is_special'] ?? false;
                    @endphp
                    <tr>
                        <td>
                            <div class="font-semibold">{{ $isSpecial ? 'Apply' : ($metadata['title'] ?? 'Untitled page') }}</div>
                            @if($isSpecial)
                                <span class="badge badge-neutral badge-sm mt-1">Special page</span>
                            @endif
                        </td>
                        <td class="font-semibold">/{{ $page->slug }}</td>
                        <td>
                            @if($isSpecial)
                                <span class="nexus-text-muted">Recruitment</span>
                            @else
                                {{ ($metadata['audience'] ?? 'public') === 'member' ? 'Alliance members' : 'Public' }}
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $statusLabel === 'Live' ? 'badge-success' : ($statusLabel === 'Live with unpublished changes' ? 'badge-warning' : ($statusLabel === 'Unpublished' ? 'badge-error' : 'badge-ghost')) }}">
                                {{ $statusLabel }}
                            </span>
                        </td>
                        <td data-order="{{ $page->updated_at?->timestamp ?? 0 }}">
                            <div class="text-sm nexus-text-muted">
                                Updated {{ $page->updated_at?->diffForHumans() ?? 'recently' }}
                            </div>
                            <div class="text-sm {{ $publishedAt ? 'text-base-content' : 'nexus-text-muted' }}">
                                {{ $publishedAt ? 'Published ' . $publishedAt->diffForHumans() : 'Never published' }}
                            </div>
                        </td>
                        <td class="text-right">
                            <a href="{{ route('admin.customization.edit', $page) }}" class="btn btn-primary btn-outline btn-sm">
                                <x-icon name="o-pencil" class="size-4" />
                                Edit
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 text-center text-sm nexus-text-muted">No custom pages have been configured yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
