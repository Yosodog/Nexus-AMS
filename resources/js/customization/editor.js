import EditorJS from '@editorjs/editorjs';
import Embed from '@editorjs/embed';
import Header from '@editorjs/header';
import ImageTool from '@editorjs/image';
import List from '@editorjs/list';
import Paragraph from '@editorjs/paragraph';
import Quote from '@editorjs/quote';

// Bootstrap the admin customization editor with a curated Editor.js toolset.

/**
 * Safely parse JSON stored on data attributes.
 *
 * @param {string|null} value
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
 * Convert snake_case actions into human readable labels.
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
 * @param {string|null} value
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
 * @param {string|null} timestamp
 * @param {string|null} userName
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
        meta.textContent = `${formatTimestamp(log?.created_at)} — ${userName}`;

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

/**
 * Build default block payloads for quick insert shortcuts.
 *
 * @param {string} type
 * @param {DOMStringMap} dataset
 * @returns {object}
 */
function presetBlockData(type, dataset) {
    if (type === 'embed') {
        const service = dataset && dataset.customizationService ? dataset.customizationService : 'youtube';

        return {
            service: service,
            source: '',
            embed: '',
            url: '',
            caption: '',
        };
    }

    return {};
}

document.addEventListener('DOMContentLoaded', () => {
    const holder = document.getElementById('customization-editor');

    if (!holder) {
        return;
    }

    const endpoints = parseJson(holder.dataset.endpoints, {});
    let blocks = parseJson(holder.dataset.blocks, []);
    if (!Array.isArray(blocks)) {
        blocks = [];
    }

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

    const editor = new EditorJS({
        holder: holder,
        data: {
            blocks: blocks,
        },
        inlineToolbar: false,
        tools: {
            paragraph: {
                class: Paragraph,
                inlineToolbar: ['bold', 'italic'],
            },
            header: {
                class: Header,
                inlineToolbar: ['bold', 'italic'],
                config: {
                    levels: [2, 3, 4],
                    defaultLevel: 2,
                },
            },
            list: {
                class: List,
                inlineToolbar: true,
            },
            quote: {
                class: Quote,
                inlineToolbar: false,
            },
            image: {
                class: ImageTool,
                config: {
                    endpoints: {
                        byFile: endpoints.upload,
                    },
                    additionalRequestHeaders: {
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    captionPlaceholder: 'Add a caption',
                },
            },
            embed: {
                class: Embed,
                config: {
                    services: {
                        youtube: true,
                    },
                },
                toolbox: {
                    title: 'YouTube Embed',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M21.8 8.001a2.745 2.745 0 0 0-1.93-1.94C18.312 5.5 12 5.5 12 5.5s-6.312 0-7.87.561A2.745 2.745 0 0 0 2.2 8.001 28.64 28.64 0 0 0 1.5 12a28.64 28.64 0 0 0 .7 3.999 2.745 2.745 0 0 0 1.93 1.94C5.688 18.5 12 18.5 12 18.5s6.312 0 7.87-.561a2.745 2.745 0 0 0 1.93-1.94 28.64 28.64 0 0 0 .7-3.999 28.64 28.64 0 0 0-.7-3.999ZM10 15.02V8.98L15.5 12Z"/></svg>',
                },
            },
        },
    });

    editor.isReady
        .then(() => {
            const quickInsertButtons = document.querySelectorAll('[data-customization-block]');

            quickInsertButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();

                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }

                    const blockType = button.dataset.customizationBlock;

                    if (!blockType) {
                        return;
                    }

                    try {
                        editor.blocks.insert(
                            blockType,
                            presetBlockData(blockType, button.dataset),
                            undefined,
                            undefined,
                            true,
                        );
                    } catch (error) {
                        console.error('Failed to insert block', error);
                    }
                });
            });
        })
        .catch((error) => {
            console.error('Editor failed to initialize', error);
        });

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

    async function gatherBlocks() {
        const output = await editor.save();

        return Array.isArray(output.blocks) ? output.blocks : [];
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
        await disableWhileRunning(button, async () => {
            const payload = {
                blocks: await gatherBlocks(),
                metadata: {
                    origin: 'admin-ui',
                },
            };

            try {
                const response = await postJson(endpoints.preview, csrfToken, payload);
                if (previewPane && typeof response.html === 'string') {
                    previewPane.innerHTML = response.html;
                }
                setStatusBadge(previewStatus, `Preview updated ${formatTimestamp(new Date().toISOString())}`, 'success');
                await refreshActivity();
            } catch (error) {
                console.error(error);
                setStatusBadge(previewStatus, error.message ?? 'Preview failed', 'danger');
            }
        });
    }

    async function handleDraft(button) {
        await disableWhileRunning(button, async () => {
            const payload = {
                blocks: await gatherBlocks(),
                metadata: {
                    origin: 'admin-ui',
                },
            };

            try {
                const response = await postJson(endpoints.draft, csrfToken, payload);
                updateStatus(statusContainer, response?.page?.status ?? page.status);
                updateFromVersion(response.version, false);
                showTransientAlert(holder, 'Draft saved successfully.', 'success');
                await refreshActivity();
            } catch (error) {
                console.error(error);
                showTransientAlert(holder, error.message ?? 'Saving draft failed.', 'danger');
            }
        });
    }

    async function handlePublish(button) {
        await disableWhileRunning(button, async () => {
            const payload = {
                blocks: await gatherBlocks(),
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
        if (!versionsAlert) {
            return;
        }

        versionsAlert.className = 'alert alert-info small';
        versionsAlert.textContent = 'Loading version history…';
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

            if (response.version?.editor_state) {
                await editor.render({ blocks: response.version.editor_state });
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
