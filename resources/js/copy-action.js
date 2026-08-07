const initializedScopes = new WeakSet();

const setStatus = (copyAction, message, state) => {
    const status = copyAction.querySelector('[data-copy-status]');

    copyAction.dataset.copyState = state;

    if (!status) {
        return;
    }

    status.textContent = '';
    queueMicrotask(() => {
        status.textContent = message;
    });
};

const selectReadableValue = (copyAction, canonicalValue) => {
    const readableValue = copyAction.querySelector('[data-copy-readable]');
    const selection = window.getSelection();

    if (!readableValue || !selection) {
        return false;
    }

    selection.removeAllRanges();

    const range = document.createRange();
    range.selectNodeContents(readableValue);
    selection.addRange(range);

    return selection.toString() === canonicalValue;
};

export const copyCanonicalValue = async (copyAction, clipboard = navigator.clipboard) => {
    const trigger = copyAction.querySelector('[data-copy-trigger]');
    const canonicalValue = copyAction.dataset.copyValue;

    if (
        !(trigger instanceof HTMLButtonElement)
        || !canonicalValue
        || trigger.disabled
        || copyAction.dataset.copyBusy === 'true'
    ) {
        return;
    }

    copyAction.dataset.copyBusy = 'true';
    trigger.setAttribute('aria-disabled', 'true');
    trigger.setAttribute('aria-busy', 'true');

    try {
        if (!clipboard || typeof clipboard.writeText !== 'function') {
            throw new Error('Clipboard API unavailable.');
        }

        await clipboard.writeText(canonicalValue);
        window.getSelection()?.removeAllRanges();
        setStatus(copyAction, copyAction.dataset.copySuccessMessage || 'Copied.', 'success');
    } catch {
        const selected = selectReadableValue(copyAction, canonicalValue);
        const message = selected
            ? copyAction.dataset.copySelectedMessage
            : copyAction.dataset.copyManualMessage;

        setStatus(copyAction, message || 'Copy unavailable.', 'fallback');
    } finally {
        delete copyAction.dataset.copyBusy;
        trigger.removeAttribute('aria-disabled');
        trigger.removeAttribute('aria-busy');
    }
};

const handleCopyActivation = (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const trigger = event.target.closest('[data-copy-trigger]');
    const copyAction = trigger?.closest('[data-copy-action]');

    if (!copyAction) {
        return;
    }

    void copyCanonicalValue(copyAction);
};

export const initializeCopyActions = (scope = document) => {
    scope.querySelectorAll('[data-copy-trigger]').forEach((trigger) => {
        trigger.hidden = false;
    });

    if (initializedScopes.has(scope)) {
        return;
    }

    scope.addEventListener('click', handleCopyActivation);
    initializedScopes.add(scope);
};
