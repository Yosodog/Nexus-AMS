@extends('layouts.admin')

@section('title', 'Staff work queue')

@section('content')
    @php
        $urgencyPresentation = [
            'urgent' => ['label' => 'Urgent', 'intent' => 'failure', 'icon' => 'exclamation-triangle'],
            'attention' => ['label' => 'Needs attention', 'intent' => 'warning', 'icon' => 'exclamation-triangle'],
            'routine' => ['label' => 'Routine', 'intent' => 'neutral', 'icon' => 'clock'],
        ];
        $filterQuery = $filters->toArray();
        $nextAgeDirection = $filters->direction === 'desc' ? 'asc' : 'desc';
    @endphp

    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <p class="nexus-eyebrow">Staff operations</p>
            <h1 class="nexus-page-title">Staff work queue</h1>
            <p class="nexus-page-summary">
                Find pending work you can review. Open an item to make a decision in its usual section.
                Last updated <x-time.display :value="$generatedAt" :server-now="now()" label="Work queue updated" />.
            </p>
        </div>
        <div class="nexus-page-header__actions">
            <a href="{{ route('admin.work-queue.index', array_merge($filterQuery, ['refresh' => 1])) }}" class="btn btn-outline btn-sm">
                <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" />
                Refresh queue
            </a>
        </div>
    </header>

    @if ($failures !== [])
        <div class="alert alert-warning mb-5" role="status" aria-live="polite">
            <x-icon name="o-exclamation-triangle" class="size-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Some types of work could not be loaded.</p>
                <p class="text-sm">
                    {{ collect($failures)->pluck('label')->join(', ', ' and ') }}
                    {{ count($failures) === 1 ? 'is' : 'are' }} temporarily unavailable. Other pending work is still shown. Refresh to try again.
                </p>
            </div>
        </div>
    @endif

    @if ($savedViews !== [])
        <section class="nexus-panel mb-5" aria-labelledby="saved-views-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="saved-views-title" class="nexus-section-title">Saved views</h2>
                    <p class="nexus-body-muted mt-1">Only you can see your saved views.</p>
                </div>
            </div>
            <ul class="flex flex-wrap gap-2 px-5 pb-5">
                @foreach ($savedViews as $savedView)
                    <li class="join" data-saved-view-id="{{ $savedView['id'] }}">
                        <a
                            href="{{ route('admin.work-queue.index', ['saved_view' => $savedView['id']]) }}"
                            @class(['btn btn-sm join-item', 'btn-primary' => ($selectedSavedView['id'] ?? null) === $savedView['id'], 'btn-outline' => ($selectedSavedView['id'] ?? null) !== $savedView['id']])
                        >
                            {{ $savedView['name'] }}
                        </a>
                        <form method="POST" action="{{ route('admin.work-queue.saved-views.destroy', $savedView['id']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline join-item" aria-label="Delete saved view {{ $savedView['name'] }}">
                                <x-icon name="o-x-mark" class="size-4" aria-hidden="true" />
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="nexus-panel mb-5" aria-labelledby="queue-filters-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="queue-filters-title" class="nexus-section-title">Filter the queue</h2>
                <p class="nexus-body-muted mt-1">You can bookmark or share the filtered page.</p>
            </div>
            @if ($filters->hasActiveFilters() || $selectedSavedView)
                <a href="{{ route('admin.work-queue.index') }}" class="btn btn-ghost btn-sm">Clear filters</a>
            @endif
        </div>

        <form method="GET" action="{{ route('admin.work-queue.index') }}" class="grid gap-4 px-5 pb-5 md:grid-cols-2 xl:grid-cols-5">
            <label class="form-control xl:col-span-2" for="queue-search">
                <span class="label-text mb-1 font-medium">Search</span>
                <input
                    id="queue-search"
                    name="q"
                    type="search"
                    value="{{ $filters->search }}"
                    class="input input-bordered w-full"
                    placeholder="Subject, nation, request, or reference"
                >
            </label>

            <label class="form-control" for="queue-type">
                <span class="label-text mb-1 font-medium">Type</span>
                <select id="queue-type" name="type" class="select select-bordered w-full">
                    <option value="">All types I can access</option>
                    @foreach ($types as $type => $label)
                        <option value="{{ $type }}" @selected($filters->type === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-control" for="queue-urgency">
                <span class="label-text mb-1 font-medium">Urgency</span>
                <select id="queue-urgency" name="urgency" class="select select-bordered w-full">
                    <option value="">All urgency levels</option>
                    @foreach ($urgencyPresentation as $urgency => $presentation)
                        <option value="{{ $urgency }}" @selected($filters->urgency === $urgency)>{{ $presentation['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-control" for="queue-owner">
                <span class="label-text mb-1 font-medium">Owner</span>
                <select id="queue-owner" name="owner" class="select select-bordered w-full">
                    <option value="">All owners</option>
                    @foreach ($owners as $ownerKey => $ownerLabel)
                        <option value="{{ $ownerKey }}" @selected($filters->owner === $ownerKey)>{{ $ownerLabel }}</option>
                    @endforeach
                </select>
            </label>

            <input type="hidden" name="sort" value="{{ $filters->sort }}">
            <input type="hidden" name="direction" value="{{ $filters->direction }}">

            <div class="flex items-end gap-2 md:col-span-2 xl:col-span-5">
                <button type="submit" class="btn btn-primary">Apply filters</button>
            </div>
        </form>

        <form method="POST" action="{{ route('admin.work-queue.saved-views.store') }}" class="flex flex-col gap-3 border-t border-base-300 px-5 py-4 sm:flex-row sm:items-end">
            @csrf
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <label class="form-control w-full max-w-sm" for="saved-view-name">
                <span class="label-text mb-1 font-medium">Save this filter set</span>
                <input id="saved-view-name" name="name" class="input input-bordered input-sm" maxlength="60" required placeholder="For example: urgent finance">
            </label>
            <button type="submit" class="btn btn-outline btn-sm">Save view</button>
        </form>
    </section>

    <section class="nexus-panel" aria-labelledby="queue-results-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="queue-results-title" class="nexus-section-title">Pending work</h2>
                <p class="nexus-body-muted mt-1">
                    Showing {{ number_format($items->total()) }} of {{ number_format($unfilteredTotal) }} items.
                </p>
            </div>
            <div class="flex flex-wrap gap-2" aria-label="Pending work totals">
                @foreach ($types as $type => $label)
                    @if (isset($failures[$type]))
                        <span class="badge badge-warning">{{ $label }} is unavailable</span>
                    @else
                        <a href="{{ route('admin.work-queue.index', ['type' => $type, 'sort' => 'age', 'direction' => 'desc']) }}" class="badge badge-outline">
                            {{ $label }} {{ number_format($counts[$type] ?? 0) }}
                        </a>
                    @endif
                @endforeach
            </div>
        </div>

        @if ($items->isEmpty())
            <div class="px-5 py-12 text-center" role="status">
                <span class="nexus-icon-box mx-auto mb-3 bg-base-200 text-base-content/70">
                    <x-icon :name="$filters->hasActiveFilters() ? 'o-funnel' : 'o-check-circle'" class="size-6" aria-hidden="true" />
                </span>
                @if ($filters->hasActiveFilters() || $selectedSavedView)
                    <h3 class="font-semibold text-base-content">No work matches these filters</h3>
                    <p class="nexus-body-muted mt-1">Clear or adjust the filters to see more work.</p>
                @elseif ($failures !== [])
                    <h3 class="font-semibold text-base-content">No pending work is available right now</h3>
                    <p class="nexus-body-muted mt-1">Some work could not be loaded, so there may still be items waiting for review.</p>
                @else
                    <h3 class="font-semibold text-base-content">You are caught up</h3>
                    <p class="nexus-body-muted mt-1">There is no pending work in the sections you can manage.</p>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Work item</th>
                            <th scope="col">Status</th>
                            <th scope="col">Urgency</th>
                            <th scope="col">Assigned to</th>
                            <th scope="col">
                                <a href="{{ route('admin.work-queue.index', array_merge($filterQuery, ['sort' => 'age', 'direction' => $nextAgeDirection])) }}" class="inline-flex items-center gap-1">
                                    Created
                                    <span class="sr-only">Sort {{ $nextAgeDirection === 'desc' ? 'oldest first' : 'newest first' }}</span>
                                    <x-icon name="o-arrows-up-down" class="size-4" aria-hidden="true" />
                                </a>
                            </th>
                            <th scope="col"><span class="sr-only">Next action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php($urgency = $urgencyPresentation[$item['urgency']])
                            <tr data-work-item-key="{{ $item['key'] }}">
                                <td>
                                    <p class="font-semibold text-base-content">{{ $item['subject'] }}</p>
                                    <p class="mt-1 text-sm text-base-content/70">{{ $item['type_label'] }}</p>
                                </td>
                                <td>
                                    <x-nexus-status
                                        :label="$item['status_label']"
                                        :intent="$item['status_intent']"
                                        :icon="$item['status_icon']"
                                    />
                                </td>
                                <td>
                                    <x-nexus-status
                                        :label="$urgency['label']"
                                        :intent="$urgency['intent']"
                                        :icon="$urgency['icon']"
                                    />
                                    @if ($item['due_at'])
                                        <span class="mt-1 block text-sm text-base-content/70">
                                            Due <x-time.display :value="$item['due_at']" :server-now="now()" label="Work item due" />
                                        </span>
                                    @endif
                                </td>
                                <td>{{ $item['owner_label'] ?: 'Unassigned' }}</td>
                                <td>
                                    <x-time.display :value="$item['created_at']" :server-now="now()" label="Work item created" />
                                </td>
                                <td class="text-right">
                                    <a href="{{ $item['url'] }}" class="btn btn-outline btn-sm">
                                        {{ $item['next_action_label'] }}
                                        <x-icon name="o-arrow-top-right-on-square" class="size-4" aria-hidden="true" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($items->hasPages())
                <div class="border-t border-base-300 px-5 py-4">
                    {{ $items->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
