const MAX_RETRY_AFTER_SECONDS = 3600;
let globalEventsBound = false;
let globalState = null;

export const getAppName = () => document.querySelector('meta[name="application-name"]')?.content?.trim() || 'the application';

export class AsyncRequestError extends Error {
    constructor(message, options = {}) {
        super(message);
        this.name = 'AsyncRequestError';
        this.status = options.status ?? 0;
        this.state = options.state ?? 'temporary_failure';
        this.retryAfter = options.retryAfter ?? null;
        this.supportId = options.supportId ?? null;
    }
}

const boundedRetryAfter = (seconds) => {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return null;
    }

    return Math.min(Math.ceil(seconds), MAX_RETRY_AFTER_SECONDS);
};

export const parseRetryAfter = (response) => {
    const value = response.headers.get('Retry-After');

    if (!value) {
        return null;
    }

    const seconds = Number(value);
    if (Number.isFinite(seconds)) {
        return boundedRetryAfter(seconds);
    }

    const retryAt = Date.parse(value);
    if (Number.isNaN(retryAt)) {
        return null;
    }

    return boundedRetryAfter((retryAt - Date.now()) / 1000);
};

const parseResponseBody = async (response) => {
    const contentType = response.headers.get('content-type') ?? '';

    if (contentType.includes('application/json')) {
        return response.json();
    }

    const text = await response.text();

    return text === '' ? null : { message: text };
};

const stateForResponse = (response, body) => {
    if (body?.state) {
        return body.state;
    }

    if (response.status === 401 || response.status === 419) {
        return 'session_expired';
    }

    if (response.status === 429) {
        return 'rate_limited';
    }

    return response.status >= 500 ? 'temporary_failure' : 'error';
};

export const requestJson = async (input, init = {}) => {
    if (!navigator.onLine) {
        throw new AsyncRequestError('You appear to be offline.', { state: 'offline' });
    }

    let response;

    try {
        response = await fetch(input, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(init.headers ?? {}),
            },
        });
    } catch (error) {
        throw new AsyncRequestError(
            navigator.onLine ? `The request could not reach ${getAppName()}.` : 'You appear to be offline.',
            { state: navigator.onLine ? 'temporary_failure' : 'offline' },
        );
    }

    const body = await parseResponseBody(response);

    if (!response.ok) {
        const state = stateForResponse(response, body);

        if (state === 'session_expired') {
            window.dispatchEvent(new CustomEvent('nexus:session-expired'));
        }

        throw new AsyncRequestError(body?.message ?? 'The request could not be completed.', {
            status: response.status,
            state,
            retryAfter: parseRetryAfter(response),
            supportId: body?.support_id ?? null,
        });
    }

    return {
        data: body,
        meta: {
            state: response.headers.get('X-Nexus-Async-State') ?? 'success',
            stale: response.headers.get('X-Nexus-Data-Stale') === 'true',
            updatedAt: response.headers.get('X-Nexus-Data-Updated-At'),
            retryAfter: parseRetryAfter(response),
        },
        response,
    };
};

export const announce = (message, region = null) => {
    const liveRegion = region ?? document.querySelector('[data-async-live-region]');

    if (!(liveRegion instanceof HTMLElement)) {
        return;
    }

    liveRegion.textContent = '';
    window.requestAnimationFrame(() => {
        liveRegion.textContent = message;
    });
};

export const setButtonBusy = (button, busy, busyLabel = 'Working…') => {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const label = button.querySelector('[data-async-button-label]');
    const spinner = button.querySelector('[data-async-button-spinner]');

    if (busy) {
        button.dataset.asyncOriginalLabel = label?.textContent ?? button.textContent ?? '';
        button.dataset.asyncBusy = 'true';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        if (label) {
            label.textContent = busyLabel;
        }

        spinner?.removeAttribute('hidden');
        return;
    }

    button.dataset.asyncBusy = 'false';
    button.disabled = false;
    button.removeAttribute('aria-busy');

    if (label && button.dataset.asyncOriginalLabel) {
        label.textContent = button.dataset.asyncOriginalLabel;
    }

    spinner?.setAttribute('hidden', '');
};

export const startRetryCountdown = (button, seconds, onTick = null) => {
    let remaining = boundedRetryAfter(seconds) ?? 0;

    if (!(button instanceof HTMLButtonElement) || remaining === 0) {
        return () => {};
    }

    button.disabled = true;
    button.setAttribute('aria-disabled', 'true');

    const tick = () => {
        onTick?.(remaining);

        if (remaining <= 0) {
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            return;
        }

        remaining -= 1;
        timeoutId = window.setTimeout(tick, 1000);
    };
    let timeoutId = null;

    tick();

    return () => {
        if (timeoutId !== null) {
            window.clearTimeout(timeoutId);
        }
    };
};

const setGlobalStatus = (state) => {
    if (globalState === 'session_expired' && state !== 'session_expired') {
        return;
    }

    globalState = state;
    document.querySelectorAll('[data-async-global-status]').forEach((status) => {
        if (!(status instanceof HTMLElement)) {
            return;
        }

        status.querySelectorAll('[data-async-global-state]').forEach((content) => {
            content.toggleAttribute('hidden', content.getAttribute('data-async-global-state') !== state);
        });
        status.toggleAttribute('hidden', state === null);
    });
};

const bindDuplicateSubmissionGuard = (root) => {
    root.querySelectorAll('form').forEach((form) => {
        const hasLivewireSubmit = form.getAttributeNames().some((attribute) => attribute.startsWith('wire:submit'));

        if (
            !(form instanceof HTMLFormElement)
            || form.dataset.duplicateGuardBound === 'true'
            || form.hasAttribute('data-allow-duplicate-submit')
            || form.method.toLowerCase() === 'get'
            || form.method.toLowerCase() === 'dialog'
            || hasLivewireSubmit
        ) {
            return;
        }

        form.dataset.duplicateGuardBound = 'true';
        form.addEventListener('submit', (event) => {
            if (form.dataset.asyncPending === 'true') {
                event.preventDefault();
                announce('This request is already being submitted.');
                return;
            }

            form.dataset.asyncPending = 'true';
            form.setAttribute('aria-busy', 'true');

            if (event.submitter instanceof HTMLElement) {
                event.submitter.setAttribute('aria-disabled', 'true');
                event.submitter.dataset.asyncBusy = 'true';
            }

            queueMicrotask(() => {
                if (!event.defaultPrevented) {
                    return;
                }

                form.dataset.asyncPending = 'false';
                form.removeAttribute('aria-busy');
                event.submitter?.removeAttribute('aria-disabled');

                if (event.submitter instanceof HTMLElement) {
                    event.submitter.dataset.asyncBusy = 'false';
                }
            });
        });
    });
};

const resetPendingForms = () => {
    document.querySelectorAll('form[data-async-pending="true"]').forEach((form) => {
        form.dataset.asyncPending = 'false';
        form.removeAttribute('aria-busy');
        form.querySelectorAll('[data-async-busy="true"]').forEach((submitter) => {
            submitter.dataset.asyncBusy = 'false';
            submitter.removeAttribute('aria-disabled');
        });
    });
};

const bindGlobalEvents = () => {
    if (globalEventsBound) {
        return;
    }

    globalEventsBound = true;
    window.addEventListener('offline', () => {
        setGlobalStatus('offline');
        announce('You are offline. Changes cannot be confirmed until the connection returns.');
    });
    window.addEventListener('online', () => {
        setGlobalStatus(null);
        announce('Connection restored.');
    });
    window.addEventListener('pageshow', resetPendingForms);
    window.addEventListener('nexus:session-expired', () => {
        setGlobalStatus('session_expired');
        announce('Your session expired. Reload the page and sign in again before continuing.');
    });
    document.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-session-reload]')) {
            window.location.reload();
        }
    });
    document.addEventListener('livewire:init', () => {
        window.Livewire?.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status !== 419) {
                    return;
                }

                preventDefault();
                window.dispatchEvent(new CustomEvent('nexus:session-expired'));
            });
        });
    });

    if (!navigator.onLine) {
        setGlobalStatus('offline');
    }
};

export const initAsyncUi = (root = document) => {
    bindGlobalEvents();
    bindDuplicateSubmissionGuard(root);
};
