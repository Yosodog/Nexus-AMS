const SUMMARY_SELECTOR = '[data-form-error-summary][data-focus-on-load="true"]';
const LINK_SELECTOR = '[data-form-error-link]';

const focusLinkedField = (link) => {
    const fragment = link.getAttribute('href');

    if (!fragment?.startsWith('#')) {
        return;
    }

    const field = document.getElementById(fragment.slice(1));

    if (!field) {
        return;
    }

    link.addEventListener('click', () => {
        window.requestAnimationFrame(() => field.focus());
    });
};

export const initializeFormRecovery = (root = document) => {
    root.querySelectorAll(LINK_SELECTOR).forEach((link) => {
        if (link.dataset.formErrorLinkReady === 'true') {
            return;
        }

        link.dataset.formErrorLinkReady = 'true';
        focusLinkedField(link);
    });

    const summary = root.querySelector(`${SUMMARY_SELECTOR}:not([data-form-error-summary-focused])`);

    if (!summary) {
        return;
    }

    summary.dataset.formErrorSummaryFocused = 'true';
    window.requestAnimationFrame(() => {
        if (summary.isConnected) {
            summary.focus();
        }
    });
};
