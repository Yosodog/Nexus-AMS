@props([
    'commands' => [],
    'entitySearchUrl' => null,
])

<dialog
    id="admin-command-palette"
    class="modal command-palette"
    data-command-palette
    @if($entitySearchUrl) data-entity-search-url="{{ $entitySearchUrl }}" @endif
    aria-labelledby="admin-command-palette-title"
    aria-describedby="admin-command-palette-help"
>
    <div class="modal-box command-palette__panel">
        <div class="command-palette__header">
            <div>
                <p class="nexus-kicker">Staff navigation</p>
                <h2 id="admin-command-palette-title" class="text-lg font-semibold">Command palette</h2>
            </div>

            <form method="dialog">
                <button type="submit" class="btn btn-ghost btn-circle btn-sm" aria-label="Close command palette">
                    <x-icon name="o-x-mark" class="size-5" />
                </button>
            </form>
        </div>

        <label class="input input-bordered command-palette__search">
            <x-icon name="o-magnifying-glass" class="size-5 opacity-60" />
            <input
                type="search"
                class="grow"
                data-command-palette-input
                placeholder="Search permitted destinations{{ $entitySearchUrl ? ' or members' : '' }}"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="admin-command-palette-results"
                aria-expanded="true"
            >
        </label>

        <p id="admin-command-palette-help" class="text-sm text-base-content/65">
            Results only include destinations and records your account may view. This palette never performs mutations.
        </p>

        <p class="sr-only" data-command-palette-status role="status" aria-live="polite" aria-atomic="true"></p>

        <p
            class="admin-pin-limit-message"
            data-command-pin-limit-status
            role="status"
            aria-live="polite"
            aria-atomic="true"
            hidden
        ></p>

        <div class="command-palette__results" data-command-palette-results aria-busy="false">
            <ul id="admin-command-palette-results" class="command-palette__list" role="listbox" aria-label="Command results">
                @foreach($commands as $index => $command)
                    <li
                        data-command-item
                        data-command-id="{{ $command['id'] }}"
                        data-command-order="{{ $index }}"
                        data-command-search="{{ str($command['label'].' '.$command['group'].' '.$command['keywords'])->lower() }}"
                        class="command-palette__item"
                    >
                        <a
                            href="{{ $command['route'] }}"
                            data-command-link
                            role="option"
                            aria-label="Open {{ $command['label'] }}, {{ $command['group'] }}"
                            class="command-palette__link"
                        >
                            <span class="command-palette__icon" aria-hidden="true">
                                <x-icon :name="$command['icon']" class="size-5" />
                            </span>
                            <span class="min-w-0 grow">
                                <span class="command-palette__label">{{ $command['label'] }}</span>
                                <span class="command-palette__meta">{{ $command['group'] }}</span>
                            </span>
                            <span class="command-palette__context" data-command-context hidden></span>
                        </a>

                        <button
                            type="button"
                            class="btn btn-ghost btn-circle btn-sm command-palette__favorite"
                            data-command-favorite
                            aria-pressed="false"
                            aria-label="Add {{ $command['label'] }} to favorites"
                        >
                            <x-icon name="o-star" class="size-4" />
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="command-palette__empty" data-command-palette-empty hidden>
                <x-icon name="o-magnifying-glass" class="size-6" />
                <p>No permitted commands or members match this search.</p>
            </div>

            <div class="command-palette__error" data-command-palette-error role="status" hidden>
                Member search is temporarily unavailable. Navigation commands still work.
            </div>
        </div>

        <div class="command-palette__shortcuts" aria-label="Command palette keyboard shortcuts">
            <span><kbd class="kbd kbd-xs">↑</kbd><kbd class="kbd kbd-xs">↓</kbd> navigate</span>
            <span><kbd class="kbd kbd-xs">Enter</kbd> open</span>
            <span><kbd class="kbd kbd-xs">Esc</kbd> close</span>
            <span><kbd class="kbd kbd-xs">⌘K</kbd> open anywhere</span>
        </div>
    </div>

    <form method="dialog" class="modal-backdrop">
        <button aria-label="Close command palette">close</button>
    </form>
</dialog>
