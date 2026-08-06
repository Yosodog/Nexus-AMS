@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <div class="flex justify-between flex-1 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="join-item btn btn-disabled">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="join-item btn">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="join-item btn">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="join-item btn btn-disabled">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>

        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm leading-5 text-base-content/70">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-medium">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-medium">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-medium">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div class="join">
                <span class="join relative z-0 inline-flex rtl:flex-row-reverse shadow-sm">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <x-icon-button
                            :label="__('pagination.previous')"
                            :tooltip="__('pagination.previous')"
                            class="join-item"
                            disabled
                        >
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                      d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                      clip-rule="evenodd"/>
                            </svg>
                        </x-icon-button>
                    @else
                        <x-icon-button
                            :href="$paginator->previousPageUrl()"
                            :label="__('pagination.previous')"
                            :tooltip="__('pagination.previous')"
                            rel="prev"
                            class="join-item"
                        >
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                      d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                      clip-rule="evenodd"/>
                            </svg>
                        </x-icon-button>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true">
                                <span class="join-item btn btn-disabled">{{ $element }}</span>
                            </span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page" class="join-item">
                                        <span class="join-item btn btn-active">{{ $page }}</span>
                                    </span>
                                @else
                                    <a href="{{ $url }}" class="join-item btn"
                                       aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <x-icon-button
                            :href="$paginator->nextPageUrl()"
                            :label="__('pagination.next')"
                            :tooltip="__('pagination.next')"
                            rel="next"
                            class="join-item"
                        >
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                      d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                      clip-rule="evenodd"/>
                            </svg>
                        </x-icon-button>
                    @else
                        <x-icon-button
                            :label="__('pagination.next')"
                            :tooltip="__('pagination.next')"
                            class="join-item"
                            disabled
                        >
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                      d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                      clip-rule="evenodd"/>
                            </svg>
                        </x-icon-button>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
