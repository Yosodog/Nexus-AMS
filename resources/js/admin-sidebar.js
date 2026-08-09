import {
    FAVORITES_KEY,
    NAVIGATION_STATE_EVENT,
    RECENTS_KEY,
    quickAccessEntries,
    recordRecentNavigationId,
} from './admin-navigation-state';

const MAX_QUICK_ACCESS = 5;
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
    const entries = quickAccessEntries(new Set(templatesById.keys()), MAX_QUICK_ACCESS);
    const items = entries.map(({ id, context }) => {
        const item = templatesById.get(id)?.content.firstElementChild?.cloneNode(true);

        if (!(item instanceof HTMLElement)) {
            return null;
        }

        const favoriteContext = item.querySelector('[data-admin-quick-access-favorite]');
        const recentContext = item.querySelector('[data-admin-quick-access-recent]');
        if (favoriteContext instanceof HTMLElement) {
            favoriteContext.hidden = context !== 'favorite';
        }
        if (recentContext instanceof HTMLElement) {
            recentContext.hidden = context !== 'recent';
        }

        return item;
    }).filter((item) => item instanceof HTMLElement);

    list.replaceChildren(...items);
    empty.hidden = items.length > 0;
    region.hidden = false;
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

function enhanceDepartmentAccordions(root) {
    const departments = [...root.querySelectorAll('[data-admin-department]')];

    departments.forEach((department) => {
        if (!(department instanceof HTMLDetailsElement) || department.dataset.adminDepartmentReady === 'true') {
            return;
        }

        department.dataset.adminDepartmentReady = 'true';
        const summary = department.querySelector(':scope > summary');

        const syncExpandedState = () => {
            summary?.setAttribute('aria-expanded', String(department.open));
        };

        syncExpandedState();
        department.addEventListener('toggle', () => {
            syncExpandedState();

            if (!department.open) {
                return;
            }

            departments.forEach((otherDepartment) => {
                if (otherDepartment !== department && otherDepartment instanceof HTMLDetailsElement) {
                    otherDepartment.open = false;
                }
            });
        });
    });
}

function enhanceSidebar(root) {
    if (root.dataset.adminNavigationReady !== 'true') {
        root.dataset.adminNavigationReady = 'true';
        enhanceDepartmentAccordions(root);

        root.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
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
