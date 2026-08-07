@props([
    'href',
    'title',
    'description',
    'category',
    'status' => null,
    'statusClass' => 'badge-ghost',
    'panel' => null,
    'keywords' => '',
    'external' => false,
])

<a
    href="{{ $href }}"
    data-settings-directory-item
    data-settings-keywords="{{ $keywords }}"
    @if ($panel) data-settings-open="{{ $panel }}" @endif
    {{ $attributes->class(['group flex items-center gap-4 px-4 py-4 transition-colors duration-200 hover:bg-base-200/60 motion-reduce:transition-none sm:px-5']) }}
>
    <span class="min-w-0 flex-1">
        <span class="block text-xs font-semibold text-base-content/60">{{ $category }}</span>
        <span class="mt-1 block font-semibold text-base-content group-hover:text-primary">{{ $title }}</span>
        <span class="mt-1 block max-w-3xl text-sm leading-5 text-base-content/70">{{ $description }}</span>
    </span>

    <span class="flex shrink-0 items-center gap-3">
        @if ($status)
            <span class="badge {{ $statusClass }} hidden lg:inline-flex">{{ $status }}</span>
        @endif
        <span class="hidden text-xs font-semibold text-base-content/60 sm:inline">
            {{ $external ? 'Open feature' : 'Open settings' }}
        </span>
        <x-icon name="o-arrow-right" class="size-4 text-base-content/60 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
    </span>
</a>
