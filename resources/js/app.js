import './bootstrap';

const SORT_ASC = 'asc';
const SORT_DESC = 'desc';
const themeApi = window.NexusTheme;

const normalizeValue = (value) => value.replace(/\s+/g, ' ').trim();

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

const updateSortIndicators = (table) => {
    const activeIndex = Number(table.dataset.sortColumn ?? -1);
    const direction = table.dataset.sortDirection ?? SORT_ASC;

    table.querySelectorAll('thead th').forEach((header, index) => {
        const indicator = header.querySelector('[data-sort-indicator]');
        if (!indicator) {
            return;
        }

        if (index === activeIndex) {
            indicator.textContent = direction === SORT_ASC ? '↑' : '↓';
            indicator.classList.remove('opacity-30');
            header.setAttribute('aria-sort', direction === SORT_ASC ? 'ascending' : 'descending');
            return;
        }

        indicator.textContent = '↕';
        indicator.classList.add('opacity-30');
        header.removeAttribute('aria-sort');
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
    root.querySelectorAll('table[data-sortable="true"]').forEach((table) => {
        if (table.dataset.sortableInit === 'true') {
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
            content.className = 'inline-flex min-h-10 w-full items-center gap-1.5 text-left font-inherit';
            content.setAttribute('aria-label', `Sort by ${label}`);
            content.innerHTML = `<span>${cell.innerHTML}</span><span data-sort-indicator aria-hidden="true" class="text-xs opacity-30">↕</span>`;
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
    const activeThemeMode = themeApi.read();
    themeApi.apply(activeThemeMode);

    const updateThemeButtonState = (button, isActive) => {
        button.classList.toggle('is-active', isActive);
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

            const themeMode = button.dataset.themeMode || themeApi.defaultMode;
            themeApi.set(themeMode);

            document.querySelectorAll('a[data-theme-mode], button[data-theme-mode], [role="button"][data-theme-mode]').forEach((item) => {
                updateThemeButtonState(item, item.dataset.themeMode === themeMode);
            });

            button.closest('details')?.removeAttribute('open');
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

const enableConfirmations = () => {
    if (document.documentElement.dataset.confirmationsBound === 'true') {
        return;
    }

    const dialog = document.getElementById('nexus-confirmation-dialog');
    const title = dialog?.querySelector('#nexus-confirmation-title');
    const message = dialog?.querySelector('#nexus-confirmation-message');
    const continueButton = dialog?.querySelector('[data-confirm-continue]');
    const cancelButton = dialog?.querySelector('[data-confirm-cancel]');
    let pendingAction = null;
    let pendingCancel = null;

    const requestConfirmation = (trigger, action, cancel = null) => {
        const confirmationMessage = trigger.dataset.confirm;

        if (!confirmationMessage) {
            action();
            return;
        }

        if (!dialog || !title || !message || !continueButton || typeof dialog.showModal !== 'function') {
            if (window.confirm(confirmationMessage)) {
                action();
            } else {
                cancel?.();
            }

            return;
        }

        title.textContent = trigger.dataset.confirmTitle || 'Confirm action';
        message.textContent = confirmationMessage;
        continueButton.textContent = trigger.dataset.confirmLabel || 'Continue';
        continueButton.classList.toggle('btn-error', trigger.dataset.confirmTone === 'error');
        continueButton.classList.toggle('btn-primary', trigger.dataset.confirmTone !== 'error');
        pendingAction = action;
        pendingCancel = cancel;
        dialog.showModal();
        cancelButton?.focus();
    };

    document.documentElement.dataset.confirmationsBound = 'true';
    window.NexusConfirm = (confirmationMessage, options = {}) => new Promise((resolve) => {
        const trigger = document.createElement('button');
        trigger.dataset.confirm = confirmationMessage;
        trigger.dataset.confirmTitle = options.title || 'Confirm action';
        trigger.dataset.confirmLabel = options.label || 'Continue';
        trigger.dataset.confirmTone = options.tone || 'default';
        requestConfirmation(trigger, () => resolve(true), () => resolve(false));
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
            return;
        }

        if (form.dataset.confirmBypass === 'true') {
            delete form.dataset.confirmBypass;
            return;
        }

        event.preventDefault();
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;

        requestConfirmation(form, () => {
            form.dataset.confirmBypass = 'true';
            form.requestSubmit(submitter);
        });
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest('[data-confirm]');

        if (!(trigger instanceof HTMLElement) || trigger instanceof HTMLFormElement) {
            return;
        }

        if (trigger.dataset.confirmBypass === 'true') {
            delete trigger.dataset.confirmBypass;
            return;
        }

        event.preventDefault();
        requestConfirmation(trigger, () => {
            trigger.dataset.confirmBypass = 'true';
            trigger.click();
        });
    });

    continueButton?.addEventListener('click', () => {
        const action = pendingAction;
        pendingAction = null;
        pendingCancel = null;
        dialog.close();
        action?.();
    });

    cancelButton?.addEventListener('click', () => {
        const cancel = pendingCancel;
        pendingAction = null;
        pendingCancel = null;
        dialog.close();
        cancel?.();
    });

    dialog?.addEventListener('close', () => {
        const cancel = pendingCancel;
        pendingAction = null;
        pendingCancel = null;
        cancel?.();
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

themeApi.systemDarkQuery.addEventListener('change', () => {
    const savedThemeMode = themeApi.read();
    if (savedThemeMode === themeApi.defaultMode) {
        themeApi.apply(themeApi.defaultMode, true);
    }
});

const initAppUi = (root = document) => {
    enableSortableTables(root);
    enableThemePicker(root);
    enableDepositRequests();
    enableConfirmations();
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
