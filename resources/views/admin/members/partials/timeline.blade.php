<section class="nexus-panel mb-6" aria-labelledby="member-timeline-heading">
    <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,28rem)] lg:items-start">
        <div>
            <p class="nexus-eyebrow">Member activity</p>
            <h2 id="member-timeline-heading" class="nexus-section-title">Member history</h2>
            <p class="mt-2 max-w-3xl text-sm nexus-text-muted">
                Review recent account and alliance activity you can access.
            </p>
        </div>

        <form method="GET" action="{{ route('admin.members.show', ['Nation' => $nation->id]) }}" class="grid gap-3" aria-describedby="member-timeline-filter-help">
            <input type="hidden" name="timeline_filter" value="1">
            <fieldset class="grid gap-2">
                <legend class="text-sm font-semibold">Activity categories</legend>
                <p id="member-timeline-filter-help" class="text-sm nexus-text-muted">Only categories you can access are available.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach($timeline->availableCategories as $category)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-base-300 px-3 py-2 text-sm">
                            <input
                                type="checkbox"
                                class="checkbox checkbox-primary checkbox-sm mt-0.5"
                                name="timeline_categories[]"
                                value="{{ $category->value }}"
                                @checked($timeline->isSelected($category))
                            >
                            <span>
                                <span class="block font-medium">{{ $category->label() }}</span>
                                <span class="block text-sm nexus-text-muted">{{ $category->description() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
                <a href="{{ route('admin.members.show', ['Nation' => $nation->id]) }}" class="btn btn-ghost btn-sm">Clear filters</a>
            </div>
        </form>
    </div>

    @if($timeline->hasUnavailableSources())
        <div class="mx-5 mb-5 rounded-lg border border-warning/40 bg-warning/10 p-4" role="status">
            <p class="font-semibold">Some activity is temporarily unavailable.</p>
            <p class="mt-1 text-sm">
                We could not load {{ collect($timeline->unavailableCategories)->map(fn ($category) => $category->label())->join(', ') }}. Other activity is shown below.
            </p>
        </div>
    @endif

    @if($timeline->items->isEmpty())
        <div class="border-t border-base-300 px-5 py-8 text-center">
            <p class="font-semibold">No activity matches these filters.</p>
            <p class="mt-1 text-sm nexus-text-muted">
                Try another category. Older records may no longer be available.
            </p>
        </div>
    @else
        <ol class="divide-y divide-base-300 border-t border-base-300" aria-label="Member activity, newest first">
            @foreach($timeline->items as $item)
                <li class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-start" data-timeline-source="{{ $item->sourceKey }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="badge badge-outline badge-sm">{{ $item->category->label() }}</span>
                            <x-time.display :value="$item->occurredAt" :label="$item->summary" />
                        </div>
                        <p class="mt-2 font-semibold">{{ $item->summary }}</p>
                        <p class="mt-1 text-sm nexus-text-muted">
                            {{ $item->actorKindLabel() }} &middot; {{ $item->actorLabel }}
                        </p>
                        @if($item->sourceUrl !== null)
                            <a href="{{ $item->sourceUrl }}" class="mt-2 inline-flex text-sm font-semibold text-primary hover:underline">
                                Open {{ $item->sourceLabel ?? 'record' }}
                            </a>
                        @endif
                    </div>
                    <x-nexus-status
                        :label="$item->statusLabel"
                        :intent="$item->statusIntent"
                        :icon="$item->statusIcon"
                    />
                </li>
            @endforeach
        </ol>

        @if($timeline->isTruncated)
            <p class="border-t border-base-300 px-5 py-3 text-sm nexus-text-muted" role="note">
                Showing the newest {{ $timeline->displayLimit }} records. Open a linked record to review more details.
            </p>
        @endif
    @endif
</section>
