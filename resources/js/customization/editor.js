/**
 * Helper to safely parse JSON stored in data attributes.
 *
 * @param {string|undefined} value
 * @param {any} fallback
 * @returns {any}
 */
function parseJson(value, fallback) {
    if (!value) {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch (error) {
        console.warn('Failed to parse JSON dataset value', error);

        return fallback;
    }
}

/**
 * Convert snake_case text into title case.
 *
 * @param {string} text
 * @returns {string}
 */
function headline(text) {
    if (!text) {
        return '';
    }

    return text
        .replace(/_/g, ' ')
        .toLowerCase()
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

/**
 * Format ISO-8601 timestamps for display.
 *
 * @param {string|null|undefined} value
 * @returns {string}
 */
function formatTimestamp(value) {
    if (!value) {
        return 'Recently';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Recently';
    }

    return date.toLocaleString();
}

/**
 * Update the badge next to the preview controls.
 *
 * @param {HTMLElement|null} badge
 * @param {string} message
 * @param {string} [style]
 */
function setStatusBadge(badge, message, style = 'secondary') {
    if (!badge) {
        return;
    }

    badge.textContent = message;
    badge.className = `badge text-bg-${style}`;
}

/**
 * Refresh the audit summary cards with the latest metadata.
 *
 * @param {HTMLElement|null} container
 * @param {string|null|undefined} timestamp
 * @param {string|null|undefined} userName
 * @param {string} fallbackMessage
 */
function updateAuditSection(container, timestamp, userName, fallbackMessage) {
    if (!container) {
        return;
    }

    container.innerHTML = '';

    if (!timestamp) {
        const span = document.createElement('span');
        span.className = 'text-muted';
        span.textContent = fallbackMessage;
        container.appendChild(span);

        return;
    }

    const timeLine = document.createElement('div');
    timeLine.textContent = formatTimestamp(timestamp);

    const userLine = document.createElement('div');
    userLine.className = 'text-muted';
    userLine.textContent = `by ${userName ?? 'System'}`;

    container.appendChild(timeLine);
    container.appendChild(userLine);
}

/**
 * Reflect the page status badge in the sidebar card.
 *
 * @param {HTMLElement|null} statusContainer
 * @param {string} status
 */
function updateStatus(statusContainer, status) {
    if (!statusContainer || !status) {
        return;
    }

    const normalized = status.toString().toLowerCase();
    statusContainer.textContent = normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

/**
 * Render recent activity logs in the sidebar list.
 *
 * @param {HTMLElement|null} listElement
 * @param {Array<object>} logs
 */
function renderActivity(listElement, logs) {
    if (!listElement) {
        return;
    }

    listElement.innerHTML = '';

    if (!Array.isArray(logs) || logs.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'text-muted';
        empty.textContent = 'No activity has been recorded yet.';
        listElement.appendChild(empty);

        return;
    }

    logs.forEach((log) => {
        const item = document.createElement('li');
        item.className = 'mb-3';

        const title = document.createElement('div');
        title.className = 'fw-semibold text-uppercase small text-muted';
        title.textContent = headline(log?.action ?? '');

        const meta = document.createElement('div');
        const userName = log?.user?.name ?? 'System';
        meta.textContent = `${formatTimestamp(log?.created_at)} - ${userName}`;

        item.appendChild(title);
        item.appendChild(meta);

        if (log?.metadata && Object.keys(log.metadata).length > 0) {
            const metadataBlock = document.createElement('code');
            metadataBlock.className = 'd-block mt-1';
            metadataBlock.textContent = JSON.stringify(log.metadata);
            item.appendChild(metadataBlock);
        }

        listElement.appendChild(item);
    });
}

/**
 * Populate the version history modal table with recent entries.
 *
 * @param {HTMLElement|null} table
 * @param {Array<object>} versions
 */
function renderVersionsTable(table, versions) {
    if (!table) {
        return;
    }

    table.innerHTML = '';

    if (!Array.isArray(versions) || versions.length === 0) {
        const emptyRow = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'text-center text-muted py-3';
        cell.textContent = 'No versions recorded yet.';
        emptyRow.appendChild(cell);
        table.appendChild(emptyRow);

        return;
    }

    versions.forEach((version) => {
        const row = document.createElement('tr');

        const idCell = document.createElement('td');
        idCell.textContent = version.id;

        const statusCell = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'badge text-bg-light';
        badge.textContent = headline(version.status ?? 'unknown');
        statusCell.appendChild(badge);

        const timestampCell = document.createElement('td');
        timestampCell.textContent = formatTimestamp(version.published_at ?? version.created_at);

        const userCell = document.createElement('td');
        userCell.textContent = version?.user?.name ?? 'System';

        const actionsCell = document.createElement('td');
        actionsCell.className = 'text-end';

        const restoreDraftBtn = document.createElement('button');
        restoreDraftBtn.type = 'button';
        restoreDraftBtn.className = 'btn btn-outline-primary btn-sm me-2';
        restoreDraftBtn.dataset.restoreVersion = version.id;
        restoreDraftBtn.dataset.publish = 'false';
        restoreDraftBtn.textContent = 'Restore Draft';

        const restorePublishBtn = document.createElement('button');
        restorePublishBtn.type = 'button';
        restorePublishBtn.className = 'btn btn-outline-success btn-sm';
        restorePublishBtn.dataset.restoreVersion = version.id;
        restorePublishBtn.dataset.publish = 'true';
        restorePublishBtn.textContent = 'Restore & Publish';

        actionsCell.appendChild(restoreDraftBtn);
        actionsCell.appendChild(restorePublishBtn);

        row.appendChild(idCell);
        row.appendChild(statusCell);
        row.appendChild(timestampCell);
        row.appendChild(userCell);
        row.appendChild(actionsCell);

        table.appendChild(row);
    });
}

/**
 * Display a temporary alert anchored near the provided node.
 *
 * @param {HTMLElement|null} anchor
 * @param {string} message
 * @param {string} [type]
 */
function showTransientAlert(anchor, message, type = 'success') {
    if (!anchor) {
        return;
    }

    const alert = document.createElement('div');
    alert.className = `alert alert-${type} small mt-3`;
    alert.textContent = message;

    anchor.parentElement?.insertBefore(alert, anchor);

    setTimeout(() => {
        alert.classList.add('fade');
        alert.addEventListener('transitionend', () => alert.remove(), { once: true });
        alert.remove();
    }, 4000);
}

/**
 * Issue a JSON POST request and bubble up any validation errors.
 *
 * @param {string} url
 * @param {string} token
 * @param {Record<string, any>} [payload]
 */
async function postJson(url, token, payload = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    if (response.ok) {
        return response.json();
    }

    const data = await response.json().catch(() => ({}));
    const message = data?.message ?? 'The server rejected the request.';
    const error = new Error(message);
    error.response = data;
    throw error;
}

/**
 * Fetch the latest version and activity payload from the server.
 *
 * @param {string} url
 */
async function fetchHistory(url) {
    const response = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    if (response.ok) {
        return response.json();
    }

    throw new Error('Unable to load history.');
}

/**
 * Temporarily disable a button while an async task completes.
 *
 * @param {HTMLButtonElement|null} button
 * @param {() => Promise<any>} callback
 */
function disableWhileRunning(button, callback) {
    if (!button) {
        return callback();
    }

    button.disabled = true;
    button.classList.add('disabled');

    return callback().finally(() => {
        button.disabled = false;
        button.classList.remove('disabled');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const holder = document.getElementById('customization-editor');

    if (!holder) {
        return;
    }

    const editorElement = holder.querySelector('[data-editor-input]');

    if (!(editorElement instanceof HTMLElement)) {
        console.warn('Customization editor input not found.');

        return;
    }

    const endpoints = parseJson(holder.dataset.endpoints, {});
    const csrfToken = holder.dataset.csrf ?? '';
    const page = parseJson(holder.dataset.page, {});
    const previewPane = document.getElementById('customization-preview-pane');
    const previewStatus = document.getElementById('customization-preview-status');
    const statusContainer = document.getElementById('customization-status');
    const draftContainer = document.getElementById('customization-last-draft');
    const publishContainer = document.getElementById('customization-last-publish');
    const activityList = document.getElementById('customization-activity-list');
    const initialActivity = parseJson(holder.dataset.initialActivity, []);
    const versionsTable = document.getElementById('customization-versions-table');
    const versionsAlert = document.getElementById('customization-versions-alert');
    const versionModal = document.getElementById('customization-version-modal');
    const pagePicker = document.getElementById('customization-page-picker');

    renderActivity(activityList, initialActivity);

    if (pagePicker) {
        pagePicker.addEventListener('change', (event) => {
            const target = event.target;
            if (target instanceof HTMLSelectElement && target.value) {
                window.location.href = target.value;
            }
        });
    }

    let editorInstance = typeof window.getCkeditorInstance === 'function'
        ? window.getCkeditorInstance(editorElement)
        : null;
    let pendingContent = null;

    if (!editorInstance) {
        editorElement.addEventListener('ckeditor:ready', (event) => {
            editorInstance = event.detail?.editor ?? null;
            if (editorInstance && pendingContent !== null) {
                editorInstance.setData(pendingContent);
                pendingContent = null;
            }
        }, { once: true });
    }

    async function getContent() {
        if (editorInstance && typeof editorInstance.getData === 'function') {
            return editorInstance.getData();
        }

        if (editorElement instanceof HTMLTextAreaElement) {
            return editorElement.value;
        }

        return '';
    }

    function setContent(html) {
        const normalized = typeof html === 'string' ? html : '';

        if (editorInstance && typeof editorInstance.setData === 'function') {
            editorInstance.setData(normalized);
            pendingContent = null;
        } else if (editorElement instanceof HTMLTextAreaElement) {
            editorElement.value = normalized;
            pendingContent = normalized;
        }
    }

    async function refreshActivity() {
        if (!endpoints.versions) {
            return;
        }

        try {
            const history = await fetchHistory(endpoints.versions);
            renderActivity(activityList, history.activity ?? []);
        } catch (error) {
            console.error(error);
        }
    }

    function updateFromVersion(version, publish = false) {
        if (!version) {
            return;
        }

        if (publish && version.published_at) {
            updateAuditSection(publishContainer, version.published_at, version?.user?.name, 'Never published');
        } else if (version.created_at) {
            updateAuditSection(draftContainer, version.created_at, version?.user?.name, 'No drafts yet');
        }
    }

    async function handlePreview(button) {
        if (!endpoints.preview) {
            return;
        }

        await disableWhileRunning(button, async () => {
            const payload = {
                content: await getContent(),
                metadata: {
                    origin: 'admin-ui',
                },
            };

            try {
                const response = await postJson(endpoints.preview, csrfToken, payload);

                if (previewPane && typeof response.html === 'string') {
                    previewPane.innerHTML = response.html;
                }

                if (response.version?.created_at) {
                    updateAuditSection(draftContainer, response.version.created_at, response.version?.user?.name, 'No drafts yet');
                }

                setStatusBadge(previewStatus, 'Preview generated', 'info');
                await refreshActivity();
            } catch (error) {
                console.error(error);
                setStatusBadge(previewStatus, error.message ?? 'Preview failed', 'danger');
                showTransientAlert(holder, error.message ?? 'Preview failed.', 'danger');
            }
        });
    }

    async function handleDraft(button) {
        if (!endpoints.draft) {
            return;
        }

        await disableWhileRunning(button, async () => {
            const payload = {
                content: await getContent(),
                metadata: {
                    origin: 'admin-ui',
                },
            };

            try {
                const response = await postJson(endpoints.draft, csrfToken, payload);
                updateFromVersion(response.version, false);
                updateStatus(statusContainer, response?.page?.status ?? page.status);
                setStatusBadge(previewStatus, 'Draft saved', 'success');
                await refreshActivity();
            } catch (error) {
                console.error(error);
                showTransientAlert(holder, error.message ?? 'Save failed.', 'danger');
            }
        });
    }

    async function handlePublish(button) {
        if (!endpoints.publish) {
            return;
        }

        await disableWhileRunning(button, async () => {
            const payload = {
                content: await getContent(),
                metadata: {
                    origin: 'admin-ui',
                },
            };

            try {
                const response = await postJson(endpoints.publish, csrfToken, payload);
                updateStatus(statusContainer, response?.page?.status ?? 'published');
                updateFromVersion(response.version, true);
                if (previewPane && typeof response.html === 'string') {
                    previewPane.innerHTML = response.html;
                }
                setStatusBadge(previewStatus, 'Published successfully', 'success');
                await refreshActivity();
            } catch (error) {
                console.error(error);
                showTransientAlert(holder, error.message ?? 'Publish failed.', 'danger');
            }
        });
    }

    async function loadVersions() {
        if (!versionsAlert || !endpoints.versions) {
            return;
        }

        versionsAlert.className = 'alert alert-info small';
        versionsAlert.textContent = 'Loading version history...';
        versionsAlert.classList.remove('d-none');

        try {
            const history = await fetchHistory(endpoints.versions);
            renderVersionsTable(versionsTable, history.versions ?? []);
            renderActivity(activityList, history.activity ?? []);
            versionsAlert.classList.add('d-none');
        } catch (error) {
            console.error(error);
            versionsAlert.className = 'alert alert-danger small';
            versionsAlert.textContent = 'Unable to load version history.';
        }
    }

    async function handleRestore(versionId, publish) {
        if (!endpoints.restore) {
            return;
        }

        const confirmationMessage = publish
            ? 'Restore this version and publish it immediately?'
            : 'Restore this version as the active draft?';

        if (!window.confirm(confirmationMessage)) {
            return;
        }

        try {
            const payload = {
                version_id: versionId,
                publish,
            };
            const response = await postJson(endpoints.restore, csrfToken, payload);

            if (typeof response.content === 'string') {
                setContent(response.content);
            } else if (typeof response.version?.content === 'string') {
                setContent(response.version.content);
            }

            if (publish) {
                updateStatus(statusContainer, response?.page?.status ?? 'published');
                updateFromVersion(response.version, true);
                if (previewPane && typeof response.html === 'string') {
                    previewPane.innerHTML = response.html;
                }
                setStatusBadge(previewStatus, 'Restored and published', 'success');
            } else {
                updateFromVersion(response.version, false);
                updateStatus(statusContainer, response?.page?.status ?? page.status);
                setStatusBadge(previewStatus, 'Draft restored', 'info');
            }

            await refreshActivity();
            await loadVersions();
        } catch (error) {
            console.error(error);
            showTransientAlert(holder, error.message ?? 'Restore failed.', 'danger');
        }
    }

    const previewButton = document.getElementById('customization-preview');
    const draftButton = document.getElementById('customization-save');
    const publishButton = document.getElementById('customization-publish');

    previewButton?.addEventListener('click', () => handlePreview(previewButton));
    draftButton?.addEventListener('click', () => handleDraft(draftButton));
    publishButton?.addEventListener('click', () => handlePublish(publishButton));

    if (versionModal) {
        versionModal.addEventListener('show.bs.modal', () => {
            loadVersions();
        });
    }

    if (versionsTable) {
        versionsTable.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const button = target.closest('[data-restore-version]');

            if (!button) {
                return;
            }

            const versionId = Number.parseInt(button.dataset.restoreVersion ?? '', 10);
            const publish = button.dataset.publish === 'true';

            if (!Number.isNaN(versionId)) {
                handleRestore(versionId, publish);
            }
        });
    }
});
