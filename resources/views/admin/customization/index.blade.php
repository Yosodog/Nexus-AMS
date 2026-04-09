@extends('layouts.admin')

@section('title', 'Custom Page Management')

@section('content')
    <x-header title="Custom Page Management" separator>
        <x-slot:subtitle>Review existing custom pages and open them in the editor to update content.</x-slot:subtitle>
        <x-slot:actions>
            @if($pages->isNotEmpty())
                <a href="{{ route('admin.customization.edit', $pages->first()) }}" class="btn btn-primary btn-sm">
                    <x-icon name="o-pencil-square" class="size-4" />
                    Open Editor
                </a>
            @else
                <button class="btn btn-primary btn-sm" type="button" disabled>
                    <x-icon name="o-pencil-square" class="size-4" />
                    No pages yet
                </button>
            @endif
        </x-slot:actions>
    </x-header>

    <x-card title="Managed pages" :subtitle="$pages->count() . ' total'">
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-zebra">
                <thead>
                <tr>
                    <th>Slug</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($pages as $page)
                    @php
                        $publishedAt = optional($page->latestPublishedVersion)->published_at;
                    @endphp
                    <tr>
                        <td class="font-semibold">/{{ $page->slug }}</td>
                        <td>
                            <span class="badge {{ $page->status === \App\Models\Page::STATUS_PUBLISHED ? 'badge-success' : 'badge-warning' }}">
                                {{ $page->status === \App\Models\Page::STATUS_PUBLISHED ? 'Published' : 'Draft' }}
                            </span>
                        </td>
                        <td>
                            <div class="text-sm text-base-content/60">
                                Updated {{ $page->updated_at?->diffForHumans() ?? 'recently' }}
                            </div>
                            <div class="text-sm {{ $publishedAt ? 'text-base-content' : 'text-base-content/60' }}">
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
                        <td colspan="4" class="py-6 text-center text-sm text-base-content/60">No custom pages have been configured yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
