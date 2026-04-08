import './bootstrap';

const SORT_ASC = 'asc';
const SORT_DESC = 'desc';
const THEME_STORAGE_KEY = 'nexus-theme';
const DEFAULT_THEME_MODE = 'auto';
const SYSTEM_DARK_QUERY = window.matchMedia('(prefers-color-scheme: dark)');

const normalizeValue = (value) => value.replace(/\s+/g, ' ').trim();

const resolveThemeMode = (mode) => {
    if (mode === 'light' || mode === 'night') {
        return mode;
    }

    return SYSTEM_DARK_QUERY.matches ? 'night' : 'light';
};

const normalizeThemeMode = (mode) => {
    if (mode === 'light' || mode === 'night' || mode === 'auto') {
        return mode;
    }

    return DEFAULT_THEME_MODE;
};

const applyTheme = (mode) => {
    const resolvedMode = normalizeThemeMode(mode || DEFAULT_THEME_MODE);
    const theme = resolveThemeMode(resolvedMode);

    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme === 'night' ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.themeMode = resolvedMode;
};

const parseSortableValue = (value) => {
    const normalized = normalizeValue(String(value ?? ''));
    const numeric = Number(normalized.replace(/[$,%]/g, '').replace(/,/g, ''));

    if (normalized !== '' && Number.isFinite(numeric)) {
        return { type: 'number', value: numeric };
    }

    const dateValue = Date.parse(normalized);
    if (!Number.isNaN(dateValue)) {
        return { type: 'date', value: dateValue };
    }

    return { type: 'string', value: normalized.toLowerCase() };
};

const compareSortableValues = (left, right) => {
    if (left.type === right.type) {
        if (left.value < right.value) {
            return -1;
        }

        if (left.value > right.value) {
            return 1;
        }

        return 0;
    }

    return String(left.value).localeCompare(String(right.value), undefined, {
        numeric: true,
        sensitivity: 'base',
    });
};

const closeModal = (modal) => {
    if (!modal) {
        return;
    }

    modal.dispatchEvent(new Event('hide.bs.modal'));

    if (typeof modal.close === 'function' && modal.tagName === 'DIALOG') {
        if (modal.open) {
            modal.close();
        }
        modal.dispatchEvent(new Event('hidden.bs.modal'));
        return;
    }

    modal.classList.remove('show');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    modal.dispatchEvent(new Event('hidden.bs.modal'));
};

const openModal = (modal) => {
    if (!modal) {
        return;
    }

    modal.dispatchEvent(new Event('show.bs.modal'));

    if (typeof modal.showModal === 'function' && modal.tagName === 'DIALOG') {
        if (!modal.open) {
            modal.showModal();
        }
        modal.dispatchEvent(new Event('shown.bs.modal'));
        return;
    }

    modal.classList.add('show');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    modal.dispatchEvent(new Event('shown.bs.modal'));
};

const enableMobileTableScrolling = (root = document) => {
    if (window.innerWidth >= 768) {
        return;
    }

    root.querySelectorAll('main table').forEach((table) => {
        if (table.closest('.overflow-x-auto, .table-responsive')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'overflow-x-auto';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
};

const enableBootstrapTooltipCompatibility = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"], [data-bs-tooltip="true"]').forEach((element) => {
        const title = element.getAttribute('title');
        if (!title) {
            return;
        }

        element.classList.add('tooltip');
        element.setAttribute('data-tip', title);
        element.dataset.tooltipReady = 'true';
    });
};

const updateSortIndicators = (table) => {
    const activeIndex = Number(table.dataset.sortColumn ?? -1);
    const direction = table.dataset.sortDirection ?? SORT_ASC;

    table.querySelectorAll('[data-sort-indicator]').forEach((indicator, index) => {
        if (index === activeIndex) {
            indicator.textContent = direction === SORT_ASC ? '↑' : '↓';
            indicator.classList.remove('opacity-30');
            return;
        }

        indicator.textContent = '↕';
        indicator.classList.add('opacity-30');
    });
};

const sortTable = (table, columnIndex, direction = SORT_ASC) => {
    const tbody = table.tBodies[0];
    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.rows).map((row, index) => ({ row, index }));

    rows.sort((left, right) => {
        const leftCell = left.row.cells[columnIndex];
        const rightCell = right.row.cells[columnIndex];

        const leftValue = parseSortableValue(leftCell?.dataset.order ?? leftCell?.textContent ?? '');
        const rightValue = parseSortableValue(rightCell?.dataset.order ?? rightCell?.textContent ?? '');

        const result = compareSortableValues(leftValue, rightValue);
        if (result !== 0) {
            return direction === SORT_ASC ? result : -result;
        }

        return left.index - right.index;
    });

    rows.forEach(({ row }) => tbody.appendChild(row));
    table.dataset.sortColumn = String(columnIndex);
    table.dataset.sortDirection = direction;
    updateSortIndicators(table);
};

const enableSortableTables = (root = document) => {
    root.querySelectorAll('table').forEach((table) => {
        if (table.dataset.sortable === 'false' || table.dataset.sortableInit === 'true') {
            return;
        }

        const headerRow = table.tHead?.rows?.[0];
        const tbody = table.tBodies?.[0];
        if (!headerRow || !tbody || tbody.rows.length < 2) {
            return;
        }

        Array.from(headerRow.cells).forEach((cell, index) => {
            const label = normalizeValue(cell.textContent ?? '');
            if (!label || /^(actions?|controls?)$/i.test(label) || cell.dataset.sortable === 'false') {
                return;
            }

            const content = document.createElement('button');
            content.type = 'button';
            content.className = 'inline-flex w-full items-center gap-1 text-left font-inherit';
            content.innerHTML = `<span>${cell.innerHTML}</span><span data-sort-indicator class="text-xs opacity-30">↕</span>`;
            cell.innerHTML = '';
            cell.classList.add('cursor-pointer', 'select-none');
            cell.appendChild(content);

            content.addEventListener('click', () => {
                const currentColumn = Number(table.dataset.sortColumn ?? -1);
                const nextDirection = currentColumn === index && table.dataset.sortDirection === SORT_ASC
                    ? SORT_DESC
                    : SORT_ASC;

                sortTable(table, index, nextDirection);
            });
        });

        table.dataset.sortableInit = 'true';
        updateSortIndicators(table);
    });
};

const enableBootstrapModalCompatibility = () => {
    if (document.body.dataset.modalCompatibilityBound === 'true') {
        return;
    }

    document.body.dataset.modalCompatibilityBound = 'true';

    document.addEventListener('click', (event) => {
        const openTrigger = event.target.closest('[data-bs-toggle="modal"][data-bs-target]');
        if (openTrigger) {
            event.preventDefault();
            openModal(document.querySelector(openTrigger.getAttribute('data-bs-target')));
            return;
        }

        const closeTrigger = event.target.closest('[data-bs-dismiss="modal"]');
        if (closeTrigger) {
            event.preventDefault();
            closeModal(closeTrigger.closest('.modal, dialog'));
            return;
        }

        const backdrop = event.target.closest('.modal.show:not(dialog)');
        if (backdrop && event.target === backdrop) {
            closeModal(backdrop);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.modal.show, dialog[open]').forEach((modal) => {
            closeModal(modal);
        });
    });
};

const enableBootstrapCollapseCompatibility = () => {
    if (document.body.dataset.collapseCompatibilityBound === 'true') {
        return;
    }

    document.body.dataset.collapseCompatibilityBound = 'true';

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-bs-toggle="collapse"][data-bs-target]');
        if (!trigger) {
            return;
        }

        event.preventDefault();
        const target = document.querySelector(trigger.getAttribute('data-bs-target'));
        if (!target) {
            return;
        }

        target.classList.toggle('show');
    });
};

const enableThemePicker = (root = document) => {
    const activeThemeMode = normalizeThemeMode(localStorage.getItem(THEME_STORAGE_KEY) || document.documentElement.dataset.themeMode || DEFAULT_THEME_MODE);
    applyTheme(activeThemeMode);

    root.querySelectorAll('[data-theme-mode]').forEach((button) => {
        if (button.dataset.themeBound === 'true') {
            button.classList.toggle('menu-active', button.dataset.themeMode === activeThemeMode);
            return;
        }

        button.dataset.themeBound = 'true';
        button.classList.toggle('menu-active', button.dataset.themeMode === activeThemeMode);

        button.addEventListener('click', (event) => {
            event.preventDefault();

            const themeMode = button.dataset.themeMode || DEFAULT_THEME_MODE;
            if (themeMode === DEFAULT_THEME_MODE) {
                localStorage.removeItem(THEME_STORAGE_KEY);
            } else {
                localStorage.setItem(THEME_STORAGE_KEY, themeMode);
            }

            applyTheme(themeMode);

            document.querySelectorAll('[data-theme-mode]').forEach((item) => {
                item.classList.toggle('menu-active', item.dataset.themeMode === themeMode);
            });
        });
    });
};

const signalPageReady = (source = 'app') => {
    document.dispatchEvent(new CustomEvent('codex:page-ready', {
        detail: { source },
    }));

    window.dispatchEvent(new CustomEvent('codex:page-ready', {
        detail: { source },
    }));
};

SYSTEM_DARK_QUERY.addEventListener('change', () => {
    const savedThemeMode = normalizeThemeMode(localStorage.getItem(THEME_STORAGE_KEY) || DEFAULT_THEME_MODE);
    if (savedThemeMode === DEFAULT_THEME_MODE) {
        applyTheme(DEFAULT_THEME_MODE);
    }
});

const initAppUi = (root = document) => {
    enableMobileTableScrolling(root);
    enableBootstrapTooltipCompatibility(root);
    enableSortableTables(root);
    enableThemePicker(root);
};

enableBootstrapModalCompatibility();
enableBootstrapCollapseCompatibility();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initAppUi();
        signalPageReady('dom');
    });
} else {
    initAppUi();
    signalPageReady('immediate');
}

document.addEventListener('livewire:navigated', () => {
    initAppUi();
    signalPageReady('livewire:navigated');
    window.dispatchEvent(new Event('resize'));
});

window.initAppUi = initAppUi;
window.signalPageReady = signalPageReady;
