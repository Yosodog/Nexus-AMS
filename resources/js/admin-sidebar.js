import {
    FAVORITE_LIMIT_MESSAGE,
    FAVORITES_KEY,
    NAVIGATION_STATE_EVENT,
    RECENTS_KEY,
    favoriteNavigationIds,
    readFavoriteNavigationIds,
    recordRecentNavigationId,
    toggleFavoriteNavigationId,
} from './admin-navigation-state';

const MOBILE_BREAKPOINT = '(max-width: 63.999rem)';
let globalListenersBound = false;

function renderQuickAccess(root) {
    const region = root.querySelector('[data-admin-quick-access]');
    const list = root.querySelector('[data-admin-quick-access-list]');
    const empty = root.querySelector('[data-admin-quick-access-empty]');
    const templates = [...root.querySelectorAll('[data-admin-quick-access-template]')];

    if (!region || !list || !empty || templates.length === 0) {
        return;
    }

    const templatesById = new Map(templates.map((template) => [
        template.dataset.adminQuickAccessTemplate,
        template,
    ]));
    const favoriteIds = favoriteNavigationIds(new Set(templatesById.keys()));
    const items = favoriteIds.map((id) => {
        const item = templatesById.get(id)?.content.firstElementChild?.cloneNode(true);

        if (!(item instanceof HTMLElement)) {
            return null;
        }

        return item;
    }).filter((item) => item instanceof HTMLElement);

    list.replaceChildren(...items);
    empty.hidden = items.length > 0;
    region.hidden = false;
}

function syncPinControls(root) {
    const favoriteIds = new Set(readFavoriteNavigationIds());

    root.querySelectorAll('[data-admin-pin-navigation]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const id = button.dataset.adminPinNavigation;
        const label = button.dataset.adminPinLabel;
        const isPinned = favoriteIds.has(id);
        const action = isPinned ? 'Unpin' : 'Pin';

        button.classList.toggle('is-pinned', isPinned);
        button.setAttribute('aria-pressed', String(isPinned));
        button.setAttribute('aria-label', `${action} ${label}`);
        button.title = `${action} ${label}`;
    });
}

function togglePinnedNavigation(root, button) {
    const id = button.dataset.adminPinNavigation;
    const label = button.dataset.adminPinLabel;

    if (!id || !label) {
        return;
    }

    const result = toggleFavoriteNavigationId(id);
    const limitStatus = root.querySelector('[data-admin-pin-limit-status]');

    const status = root.querySelector('[data-admin-pin-status]');
    if (status instanceof HTMLElement) {
        status.textContent = result.outcome === 'limit'
            ? FAVORITE_LIMIT_MESSAGE
            : `${label} ${result.outcome === 'removed' ? 'unpinned' : 'pinned'}.`;
    }

    if (limitStatus instanceof HTMLElement) {
        limitStatus.textContent = result.outcome === 'limit' ? FAVORITE_LIMIT_MESSAGE : '';
        limitStatus.hidden = result.outcome !== 'limit';
    }
}

function closeMobileDrawer(root) {
    if (!window.matchMedia(MOBILE_BREAKPOINT).matches) {
        return;
    }

    const drawer = document.getElementById(root.dataset.adminDrawer);
    if (drawer instanceof HTMLInputElement) {
        drawer.checked = false;
    }
}

function enhanceNavigationSections(root) {
    const sections = [...root.querySelectorAll('[data-admin-navigation-section]')];

    sections.forEach((section) => {
        if (!(section instanceof HTMLDetailsElement) || section.dataset.adminNavigationSectionReady === 'true') {
            return;
        }

        section.dataset.adminNavigationSectionReady = 'true';
        const summary = section.querySelector(':scope > summary');

        const syncExpandedState = () => {
            summary?.setAttribute('aria-expanded', String(section.open));
        };

        syncExpandedState();
        section.addEventListener('toggle', syncExpandedState);
    });
}

function enhanceSidebar(root) {
    if (root.dataset.adminNavigationReady !== 'true') {
        root.dataset.adminNavigationReady = 'true';
        enhanceNavigationSections(root);

        root.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            const pinButton = event.target.closest('[data-admin-pin-navigation]');
            if (pinButton instanceof HTMLButtonElement) {
                togglePinnedNavigation(root, pinButton);

                return;
            }

            const link = event.target.closest('a[data-admin-navigation-id]');
            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }

            recordRecentNavigationId(link.dataset.adminNavigationId);
            closeMobileDrawer(root);
        });
    }

    renderQuickAccess(root);
    syncPinControls(root);
}

function bindGlobalListeners() {
    if (globalListenersBound) {
        return;
    }

    globalListenersBound = true;
    window.addEventListener(NAVIGATION_STATE_EVENT, () => initAdminSidebars());
    window.addEventListener('storage', (event) => {
        if ([FAVORITES_KEY, RECENTS_KEY].includes(event.key)) {
            initAdminSidebars();
        }
    });
}

export function initAdminSidebars(root = document) {
    root.querySelectorAll('[data-admin-navigation]').forEach(enhanceSidebar);
    bindGlobalListeners();
}
