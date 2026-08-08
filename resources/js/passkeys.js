import {
    InvalidDomainError,
    NotSupportedError,
    PasskeyError,
    PasskeyExistsError,
    Passkeys,
    UserCancelledError,
} from '@laravel/passkeys';
import { announce, setButtonBusy } from './async-ui';

const STATUS_STORAGE_KEY = 'nexus.passkeys.status';

const clientForPage = () => window.NexusPasskeysClient ?? Passkeys;

const routesFor = (root) => ({
    routes: {
        options: root.dataset.passkeyOptionsUrl,
        submit: root.dataset.passkeySubmitUrl,
    },
});

const isErrorType = (error, constructor, name) => error instanceof constructor || error?.name === name;

const messageForError = (error) => {
    if (!navigator.onLine) {
        return 'You appear to be offline. Reconnect, then try the passkey action again.';
    }

    if (isErrorType(error, NotSupportedError, 'NotSupportedError')) {
        return 'Passkeys are not supported in this browser. Use your password or another supported browser.';
    }

    if (isErrorType(error, UserCancelledError, 'UserCancelledError')) {
        return 'No passkey was used. Try again, choose another passkey, or use your password.';
    }

    if (isErrorType(error, PasskeyExistsError, 'PasskeyExistsError')) {
        return 'That passkey is already registered. Choose another device or authenticator.';
    }

    if (isErrorType(error, InvalidDomainError, 'InvalidDomainError')) {
        return 'Passkeys are unavailable on this address. Return to the configured application address and try again.';
    }

    if (/password confirmation required/i.test(error?.message ?? '')) {
        return 'Confirm your password or an existing passkey before changing passkeys.';
    }

    if (/unable to register this passkey/i.test(error?.message ?? '')) {
        return 'That passkey is already registered. Choose another device or authenticator.';
    }

    if (/too many attempts|status 429/i.test(error?.message ?? '')) {
        return 'Too many passkey attempts were made. Wait a moment before trying again.';
    }

    if (error instanceof PasskeyError || error instanceof Error) {
        return 'The passkey action could not be completed. Try again or use your existing sign-in method.';
    }

    return 'The passkey action could not be completed. Try again or use your existing sign-in method.';
};

const showStatus = (root, message, state = 'info') => {
    const status = root.querySelector('[data-passkey-status]');

    if (!(status instanceof HTMLElement)) {
        return;
    }

    status.hidden = false;
    status.dataset.passkeyStatusState = state;
    status.setAttribute('role', state === 'error' ? 'alert' : 'status');
    status.classList.toggle('alert-error', state === 'error');
    status.classList.toggle('alert-success', state === 'success');
    status.classList.toggle('alert-info', state === 'info');
    announce(message, status);
};

const safeRedirect = (candidate) => {
    if (typeof candidate !== 'string' || candidate.length === 0) {
        return '/';
    }

    const destination = new URL(candidate, window.location.origin);

    return destination.origin === window.location.origin
        ? destination.pathname + destination.search + destination.hash
        : '/';
};

const redirectAfterSuccess = (root, response, message) => {
    showStatus(root, message, 'success');
    window.setTimeout(() => {
        window.location.assign(safeRedirect(response?.redirect));
    }, 50);
};

const bindVerification = (root, client) => {
    const button = root.querySelector('[data-passkey-verify]');

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    button.addEventListener('click', async () => {
        if (root.dataset.passkeyPending === 'true') {
            return;
        }

        root.dataset.passkeyPending = 'true';
        setButtonBusy(button, true, root.dataset.passkeyBusyLabel ?? 'Checking passkey…');

        try {
            const response = await client.verify(routesFor(root));
            redirectAfterSuccess(
                root,
                response,
                root.dataset.passkeySuccessMessage ?? 'Passkey verified. Continuing…',
            );
        } catch (error) {
            showStatus(root, messageForError(error), 'error');
            root.dataset.passkeyPending = 'false';
            setButtonBusy(button, false);
            button.focus({ preventScroll: true });
        }
    });
};

const persistRegistrationStatus = () => {
    try {
        window.sessionStorage.setItem(STATUS_STORAGE_KEY, 'Passkey added. It is now available for sign-in.');
    } catch {
        // The refreshed passkey list still confirms the successful server response.
    }
};

const restoreRegistrationStatus = (root) => {
    let message = null;

    try {
        message = window.sessionStorage.getItem(STATUS_STORAGE_KEY);
        window.sessionStorage.removeItem(STATUS_STORAGE_KEY);
    } catch {
        return;
    }

    if (message) {
        showStatus(root, message, 'success');
    }
};

const bindRegistration = (root, client) => {
    const form = root.querySelector('[data-passkey-register]');
    const input = form?.querySelector('[data-passkey-name]');
    const button = form?.querySelector('[data-passkey-register-button]');

    if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement)) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (root.dataset.passkeyPending === 'true') {
            return;
        }

        const name = input.value.trim();

        if (name === '') {
            showStatus(root, 'Enter a name that will help you recognize this passkey.', 'error');
            input.focus();
            return;
        }

        root.dataset.passkeyPending = 'true';
        form.setAttribute('aria-busy', 'true');
        setButtonBusy(button, true, 'Adding passkey…');

        try {
            await client.register({
                name,
                ...routesFor(root),
            });
            showStatus(root, 'Passkey added. Refreshing your passkey list…', 'success');
            persistRegistrationStatus();
            window.history.replaceState(
                null,
                '',
                window.location.pathname + window.location.search + '#passkeys',
            );
            window.setTimeout(() => window.location.reload(), 50);
        } catch (error) {
            showStatus(root, messageForError(error), 'error');
            root.dataset.passkeyPending = 'false';
            form.removeAttribute('aria-busy');
            setButtonBusy(button, false);
            button.focus({ preventScroll: true });
        }
    });
};

const startAutofill = async (root, client) => {
    if (root.dataset.passkeyAutofill !== 'true' || typeof client.autofill !== 'function') {
        return;
    }

    try {
        const response = await client.autofill(routesFor(root));

        if (response) {
            redirectAfterSuccess(root, response, 'Passkey verified. Signing you in…');
        }
    } catch (error) {
        if (!isErrorType(error, UserCancelledError, 'UserCancelledError')) {
            showStatus(root, messageForError(error), 'error');
        }
    }
};

const revealSupportedControls = (root) => {
    root.querySelectorAll('[data-passkey-supported-control]').forEach((control) => {
        if (control instanceof HTMLElement) {
            control.hidden = false;
        }
    });
};

const revealUnsupportedMessage = (root) => {
    const message = root.querySelector('[data-passkey-unsupported]');

    if (message instanceof HTMLElement) {
        message.hidden = false;
    }
};

const initializeRoot = (root) => {
    if (!(root instanceof HTMLElement) || root.dataset.passkeyBound === 'true') {
        return;
    }

    root.dataset.passkeyBound = 'true';
    const client = clientForPage();
    let supported = false;

    try {
        supported = typeof client.isSupported === 'function' && client.isSupported();
    } catch {
        supported = false;
    }

    if (!supported) {
        revealUnsupportedMessage(root);
        return;
    }

    revealSupportedControls(root);
    restoreRegistrationStatus(root);
    bindVerification(root, client);
    bindRegistration(root, client);
    void startAutofill(root, client);
};

export const initializePasskeys = (root = document) => {
    root.querySelectorAll('[data-passkey-root]').forEach(initializeRoot);
};
