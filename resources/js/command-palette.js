import {
    FAVORITES_KEY,
    MAX_RECENTS,
    RECENTS_KEY,
    readNavigationIds,
    writeNavigationIds,
} from './admin-navigation-state';

function createEntityResult(result) {
    const item = document.createElement('li');
    item.className = 'command-palette__item';
    item.dataset.entityItem = '';

    const link = document.createElement('a');
    link.className = 'command-palette__link';
    link.href = result.url;
    link.dataset.entityLink = '';
    link.setAttribute('role', 'option');
    link.setAttribute('aria-label', `Open ${result.label}, ${result.type}`);

    const icon = document.createElement('span');
    icon.className = 'command-palette__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '#';

    const content = document.createElement('span');
    content.className = 'min-w-0 grow';

    const label = document.createElement('span');
    label.className = 'command-palette__label';
    label.textContent = result.label;

    const description = document.createElement('span');
    description.className = 'command-palette__meta';
    description.textContent = result.description;

    const type = document.createElement('span');
    type.className = 'badge badge-ghost badge-sm';
    type.textContent = result.type;

    content.append(label, description);
    link.append(icon, content, type);
    item.append(link);

    return item;
}

function enhancePalette(dialog) {
    if (dialog.dataset.commandPaletteReady === 'true') {
        return;
    }

    dialog.dataset.commandPaletteReady = 'true';

    const input = dialog.querySelector('[data-command-palette-input]');
    const list = dialog.querySelector('[data-command-palette-results]');
    const status = dialog.querySelector('[data-command-palette-status]');
    const empty = dialog.querySelector('[data-command-palette-empty]');
    const error = dialog.querySelector('[data-command-palette-error]');
    const resultsRegion = dialog.querySelector('.command-palette__results');
    const commandItems = [...dialog.querySelectorAll('[data-command-item]')];
    const allowedIds = new Set(commandItems.map((item) => item.dataset.commandId));
    const entitySearchUrl = dialog.dataset.entitySearchUrl;
    let favorites = readNavigationIds(FAVORITES_KEY).filter((id) => allowedIds.has(id));
    let recents = readNavigationIds(RECENTS_KEY).filter((id) => allowedIds.has(id));
    let requestController = null;
    let searchTimer = null;
    let restoreFocusTo = null;

    const focusableResults = () => [...list.querySelectorAll(
        '[data-command-item]:not([hidden]) [data-command-link], [data-entity-item] [data-entity-link]',
    )];

    const announce = (message) => {
        status.textContent = '';
        window.requestAnimationFrame(() => {
            status.textContent = message;
        });
    };

    const clearEntities = () => {
        list.querySelectorAll('[data-entity-item]').forEach((item) => item.remove());
    };

    const updateEmpty = () => {
        const count = focusableResults().length;
        empty.hidden = count !== 0;
        announce(count === 1 ? '1 result available.' : `${count} results available.`);
    };

    const renderCommands = (query = '') => {
        const normalizedQuery = query.trim().toLocaleLowerCase();

        commandItems
            .sort((left, right) => {
                if (normalizedQuery !== '') {
                    return Number(left.dataset.commandOrder) - Number(right.dataset.commandOrder);
                }

                const leftId = left.dataset.commandId;
                const rightId = right.dataset.commandId;
                const leftFavorite = favorites.indexOf(leftId);
                const rightFavorite = favorites.indexOf(rightId);

                if (leftFavorite !== rightFavorite && (leftFavorite >= 0 || rightFavorite >= 0)) {
                    return leftFavorite < 0 ? 1 : rightFavorite < 0 ? -1 : leftFavorite - rightFavorite;
                }

                const leftRecent = recents.indexOf(leftId);
                const rightRecent = recents.indexOf(rightId);

                if (leftRecent !== rightRecent && (leftRecent >= 0 || rightRecent >= 0)) {
                    return leftRecent < 0 ? 1 : rightRecent < 0 ? -1 : leftRecent - rightRecent;
                }

                return Number(left.dataset.commandOrder) - Number(right.dataset.commandOrder);
            })
            .forEach((item) => {
                const id = item.dataset.commandId;
                const isFavorite = favorites.includes(id);
                const isRecent = recents.includes(id);
                const favoriteButton = item.querySelector('[data-command-favorite]');
                const context = item.querySelector('[data-command-context]');
                const label = item.querySelector('.command-palette__label')?.textContent ?? 'command';

                item.hidden = normalizedQuery !== '' && !item.dataset.commandSearch.includes(normalizedQuery);
                favoriteButton.setAttribute('aria-pressed', String(isFavorite));
                favoriteButton.setAttribute('aria-label', `${isFavorite ? 'Remove' : 'Add'} ${label} ${isFavorite ? 'from' : 'to'} favorites`);
                favoriteButton.classList.toggle('is-favorite', isFavorite);
                context.hidden = !isFavorite && !isRecent;
                context.textContent = isFavorite ? 'Favorite' : isRecent ? 'Recent' : '';
                list.append(item);
            });
    };

    const searchEntities = async (query) => {
        clearEntities();
        error.hidden = true;
        requestController?.abort();

        if (!entitySearchUrl || query.trim().length < 2) {
            resultsRegion.setAttribute('aria-busy', 'false');
            updateEmpty();
            return;
        }

        if (!navigator.onLine) {
            error.textContent = 'Member search is unavailable while offline. Navigation commands still work.';
            error.hidden = false;
            updateEmpty();
            return;
        }

        requestController = new AbortController();
        resultsRegion.setAttribute('aria-busy', 'true');
        announce('Searching permitted member records.');

        try {
            const url = new URL(entitySearchUrl, window.location.origin);
            url.searchParams.set('query', query.trim());
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: requestController.signal,
            });

            if (!response.ok) {
                throw new Error(`Member search failed with status ${response.status}`);
            }

            const payload = await response.json();
            const results = Array.isArray(payload.results) ? payload.results : [];
            results.forEach((result) => list.append(createEntityResult(result)));
        } catch (searchError) {
            if (searchError.name !== 'AbortError') {
                error.textContent = 'Member search is temporarily unavailable. Navigation commands still work.';
                error.hidden = false;
            }
        } finally {
            resultsRegion.setAttribute('aria-busy', 'false');
            updateEmpty();
        }
    };

    const runSearch = () => {
        const query = input.value;
        clearEntities();
        renderCommands(query);
        clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => searchEntities(query), 180);
        updateEmpty();
    };

    const open = (trigger = null) => {
        restoreFocusTo = trigger ?? document.activeElement;
        if (!dialog.open) {
            dialog.showModal();
        }
        input.value = '';
        error.hidden = true;
        clearEntities();
        renderCommands();
        updateEmpty();
        window.requestAnimationFrame(() => input.focus());
    };

    document.querySelectorAll('[data-command-palette-open]').forEach((trigger) => {
        trigger.hidden = false;
        trigger.addEventListener('click', () => open(trigger));
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLocaleLowerCase() === 'k') {
            event.preventDefault();
            dialog.open ? dialog.close() : open();
        }
    });

    input.addEventListener('input', runSearch);
    input.addEventListener('keydown', (event) => {
        const results = focusableResults();
        if (event.key === 'ArrowDown' && results.length > 0) {
            event.preventDefault();
            results[0].focus();
        }

        if (event.key === 'Enter' && results.length === 1) {
            event.preventDefault();
            results[0].click();
        }
    });

    list.addEventListener('keydown', (event) => {
        const results = focusableResults();
        const currentIndex = results.indexOf(document.activeElement);
        if (currentIndex < 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            results[(currentIndex + direction + results.length) % results.length].focus();
        }

        if (event.key === 'Home') {
            event.preventDefault();
            results[0].focus();
        }

        if (event.key === 'End') {
            event.preventDefault();
            results.at(-1).focus();
        }
    });

    list.addEventListener('click', (event) => {
        const favoriteButton = event.target.closest('[data-command-favorite]');
        if (favoriteButton) {
            const item = favoriteButton.closest('[data-command-item]');
            const id = item.dataset.commandId;
            favorites = favorites.includes(id)
                ? favorites.filter((favoriteId) => favoriteId !== id)
                : [id, ...favorites];
            writeNavigationIds(FAVORITES_KEY, favorites);
            renderCommands(input.value);
            favoriteButton.focus();
            announce(favorites.includes(id) ? 'Command added to favorites.' : 'Command removed from favorites.');
            return;
        }

        const link = event.target.closest('[data-command-link]');
        if (link) {
            const id = link.closest('[data-command-item]').dataset.commandId;
            recents = [id, ...recents.filter((recentId) => recentId !== id)].slice(0, MAX_RECENTS);
            writeNavigationIds(RECENTS_KEY, recents);
        }
    });

    dialog.addEventListener('close', () => {
        requestController?.abort();
        clearTimeout(searchTimer);
        restoreFocusTo?.focus?.();
    });
}

export function initCommandPalettes(root = document) {
    root.querySelectorAll('[data-command-palette]').forEach(enhancePalette);
}
