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
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.themeMode = resolvedMode;
    document.documentElement.style.colorScheme = theme === 'night' ? 'dark' : 'light';
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

const enableMobileTableScrolling = (root = document) => {
    if (window.innerWidth >= 768) {
        return;
    }

    root.querySelectorAll('main table').forEach((table) => {
        if (table.closest('.overflow-x-auto')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'overflow-x-auto';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
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

const enableThemePicker = (root = document) => {
    const activeThemeMode = normalizeThemeMode(localStorage.getItem(THEME_STORAGE_KEY) || document.documentElement.dataset.themeMode || DEFAULT_THEME_MODE);
    applyTheme(activeThemeMode);

    const updateThemeButtonState = (button, isActive) => {
        button.classList.toggle('menu-active', isActive);
        button.classList.toggle('border-primary', isActive);
        button.classList.toggle('bg-primary/10', isActive);
        button.classList.toggle('shadow-sm', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    };

    root.querySelectorAll('a[data-theme-mode], button[data-theme-mode], [role="button"][data-theme-mode]').forEach((button) => {
        if (button.dataset.themeBound === 'true') {
            updateThemeButtonState(button, button.dataset.themeMode === activeThemeMode);
            return;
        }

        button.dataset.themeBound = 'true';
        updateThemeButtonState(button, button.dataset.themeMode === activeThemeMode);

        button.addEventListener('click', (event) => {
            event.preventDefault();

            const themeMode = button.dataset.themeMode || DEFAULT_THEME_MODE;
            if (themeMode === DEFAULT_THEME_MODE) {
                localStorage.removeItem(THEME_STORAGE_KEY);
            } else {
                localStorage.setItem(THEME_STORAGE_KEY, themeMode);
            }

            applyTheme(themeMode);

            document.querySelectorAll('a[data-theme-mode], button[data-theme-mode], [role="button"][data-theme-mode]').forEach((item) => {
                updateThemeButtonState(item, item.dataset.themeMode === themeMode);
            });
        });
    });
};

const enableBootstrapCompat = (root = document) => {
    const openModal = (modal) => {
        if (!modal || modal.tagName === 'DIALOG') {
            return;
        }

        modal.classList.add('show');
        modal.setAttribute('aria-modal', 'true');
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
    };

    const closeModal = (modal) => {
        if (!modal || modal.tagName === 'DIALOG') {
            return;
        }

        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');

        if (!document.querySelector('.modal.show:not(dialog)')) {
            document.body.classList.remove('modal-open');
        }
    };

    root.querySelectorAll('[data-bs-toggle="modal"]').forEach((trigger) => {
        if (trigger.dataset.bsCompatBound === 'true') {
            return;
        }

        trigger.dataset.bsCompatBound = 'true';
        trigger.addEventListener('click', (event) => {
            event.preventDefault();

            const targetSelector = trigger.getAttribute('data-bs-target');
            const modal = targetSelector ? document.querySelector(targetSelector) : null;
            openModal(modal);
        });
    });

    root.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => {
        if (button.dataset.bsCompatBound === 'true') {
            return;
        }

        button.dataset.bsCompatBound = 'true';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            closeModal(button.closest('.modal'));
        });
    });

    root.querySelectorAll('.modal:not(dialog)').forEach((modal) => {
        if (modal.dataset.bsCompatBackdropBound === 'true') {
            return;
        }

        modal.dataset.bsCompatBackdropBound = 'true';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    root.querySelectorAll('[data-bs-toggle="collapse"]').forEach((trigger) => {
        if (trigger.dataset.bsCompatBound === 'true') {
            return;
        }

        trigger.dataset.bsCompatBound = 'true';
        trigger.addEventListener('click', (event) => {
            event.preventDefault();

            const targetSelector = trigger.getAttribute('data-bs-target');
            const collapse = targetSelector ? document.querySelector(targetSelector) : null;
            if (!collapse) {
                return;
            }

            const isOpen = collapse.classList.contains('show');
            collapse.classList.toggle('show', !isOpen);
            trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });
};

const getCookie = (name) => {
    const match = document.cookie.match(new RegExp(`(^| )${name}=([^;]+)`));

    return match ? decodeURIComponent(match[2]) : null;
};

const setDepositButtonBusy = (button, isBusy) => {
    button.disabled = isBusy;
    button.dataset.depositPending = isBusy ? 'true' : 'false';
    button.classList.toggle('cursor-not-allowed', isBusy);
    button.classList.toggle('opacity-50', isBusy);
};

const resetDepositTooltip = (tooltip, originalText) => {
    if (!tooltip) {
        return;
    }

    tooltip.classList.remove('tooltip-success');

    if (originalText !== null) {
        tooltip.setAttribute('data-tip', originalText);
    }
};

const showToast = (title, message, depositCode = null, type = 'success') => {
    const toastContainer = document.getElementById('toast-container');

    if (!toastContainer) {
        return;
    }

    toastContainer.classList.remove('hidden');

    const toast = document.createElement('div');
    toast.className = `alert shadow-lg pointer-events-auto w-full sm:w-[28rem] max-w-full ${type === 'error' ? 'alert-error' : 'alert-success'}`;

    const content = document.createElement('div');
    content.className = 'flex flex-col sm:flex-row sm:items-start gap-3 w-full';

    const textContainer = document.createElement('div');
    textContainer.className = 'flex-1 text-sm space-y-1 break-words';

    const titleElement = document.createElement('div');
    titleElement.className = 'font-semibold';
    titleElement.textContent = title;

    const messageElement = document.createElement('div');
    messageElement.textContent = message;

    textContainer.appendChild(titleElement);
    textContainer.appendChild(messageElement);

    const actions = document.createElement('div');
    actions.className = 'flex flex-col sm:flex-row gap-2 w-full sm:w-auto';

    if (depositCode) {
        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'btn btn-sm btn-outline w-full sm:w-auto';
        copyButton.innerText = 'Copy Code';
        copyButton.addEventListener('click', () => {
            copyToClipboard(depositCode);
            copyButton.innerText = 'Copied!';

            setTimeout(() => {
                copyButton.innerText = 'Copy Code';
            }, 2000);
        });

        actions.appendChild(copyButton);
    }

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn btn-sm btn-ghost text-base-content border border-base-300 w-full sm:w-auto';
    closeButton.innerText = 'Dismiss';
    closeButton.addEventListener('click', () => {
        toast.remove();

        if (toastContainer.childElementCount === 0) {
            toastContainer.classList.add('hidden');
        }
    });

    actions.appendChild(closeButton);
    content.appendChild(textContainer);
    content.appendChild(actions);
    toast.appendChild(content);
    toastContainer.appendChild(toast);
};

const copyToClipboard = (text) => {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).catch((error) => {
            console.error('Clipboard API failed', error);
        });

        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
};

const parseDepositResponse = async (response) => {
    const contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        return response.json();
    }

    return { error: await response.text() };
};

const handleDepositRequestClick = async (event) => {
    const button = event.target.closest('.deposit-request-btn');

    if (!button) {
        return;
    }

    event.preventDefault();

    if (button.disabled || button.dataset.depositPending === 'true') {
        return;
    }

    const accountId = button.getAttribute('data-account-id');

    if (!accountId) {
        return;
    }

    const tooltip = button.closest('.tooltip');
    const originalTooltipText = tooltip ? tooltip.getAttribute('data-tip') : null;

    setDepositButtonBusy(button, true);

    if (tooltip) {
        tooltip.classList.add('tooltip-success');
        tooltip.setAttribute('data-tip', 'Processing deposit...');
    }

    try {
        await fetch('/sanctum/csrf-cookie', {
            method: 'GET',
            credentials: 'include',
        });

        const xsrfToken = getCookie('XSRF-TOKEN');
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (xsrfToken) {
            headers['X-XSRF-TOKEN'] = xsrfToken;
        }

        const response = await fetch(`/api/v1/accounts/${accountId}/deposit-request`, {
            method: 'POST',
            headers,
            credentials: 'include',
        });

        const data = await parseDepositResponse(response);

        if (!response.ok) {
            throw new Error(data.error || 'Failed to process deposit request.');
        }

        if (!data.deposit_code) {
            throw new Error('Deposit request succeeded, but no deposit code was returned.');
        }

        if (tooltip) {
            tooltip.setAttribute('data-tip', 'Deposit request sent!');
        }

        showToast('Deposit request created!', `Your deposit code is: ${data.deposit_code}`, data.deposit_code);

        setTimeout(() => {
            resetDepositTooltip(tooltip, originalTooltipText);
            setDepositButtonBusy(button, false);
        }, 3000);
    } catch (error) {
        console.error('Deposit request failed:', error);
        resetDepositTooltip(tooltip, originalTooltipText);
        setDepositButtonBusy(button, false);
        showToast('Deposit request failed', error.message || 'Please try again.', null, 'error');
    }
};

const enableDepositRequests = () => {
    if (document.documentElement.dataset.depositRequestsBound === 'true') {
        return;
    }

    document.documentElement.dataset.depositRequestsBound = 'true';
    document.addEventListener('click', handleDepositRequestClick);
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
    enableSortableTables(root);
    enableThemePicker(root);
    enableBootstrapCompat(root);
    enableDepositRequests();
};

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
