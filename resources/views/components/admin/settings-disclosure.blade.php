@props([
    'id',
    'title',
    'description',
    'status' => null,
    'statusClass' => 'badge-ghost',
    'open' => false,
])

<details
    id="{{ $id }}"
    data-settings-disclosure
    {{ $attributes->class(['group scroll-mt-24']) }}
    @if ($open) open @endif
>
    <summary class="flex min-h-20 cursor-pointer list-none items-center gap-4 px-4 py-4 transition-colors duration-200 hover:bg-base-200/60 motion-reduce:transition-none sm:px-5 [&::-webkit-details-marker]:hidden">
        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-base-content">{{ $title }}</span>
            <span class="mt-1 block max-w-3xl text-sm leading-5 text-base-content/70">{{ $description }}</span>
        </span>

        <span class="flex shrink-0 items-center gap-3">
            @if ($status)
                <span class="badge {{ $statusClass }} hidden sm:inline-flex">{{ $status }}</span>
            @endif
            <x-icon
                name="o-chevron-down"
                class="size-4 text-base-content/60 transition-transform duration-200 motion-reduce:transition-none group-open:rotate-180"
                aria-hidden="true"
            />
        </span>
    </summary>

    <div class="border-t border-base-300 p-4 sm:p-6">
        {{ $slot }}
    </div>
</details>
