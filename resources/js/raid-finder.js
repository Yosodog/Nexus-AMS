import {
    announce,
    AsyncRequestError,
    getAppName,
    requestJson,
    setButtonBusy,
    startRetryCountdown,
} from './async-ui';

const FILTER_KEYS = ['q', 'min_cities', 'max_cities', 'max_wars', 'min_loot'];
const FRESH_FOR_MS = 30 * 60 * 1000;

const formatMoney = (value) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
}).format(Number(value ?? 0));

const formatNumber = (value, digits = 0) => new Intl.NumberFormat(undefined, {
    maximumFractionDigits: digits,
    minimumFractionDigits: digits,
}).format(Number(value ?? 0));

const numericFilter = (form, name) => {
    const rawValue = String(new FormData(form).get(name) ?? '').trim();

    if (rawValue === '') {
        return null;
    }

    const value = Number(rawValue);

    return Number.isFinite(value) ? value : null;
};

const initializeRaidFinder = (root) => {
    if (!(root instanceof HTMLElement) || root.dataset.raidFinderBound === 'true') {
        return;
    }

    const form = root.querySelector('[data-raid-finder-form]');
    const nationInput = root.querySelector('[name="nation_id"]');
    const refreshButton = root.querySelector('[data-raid-refresh]');
    const clearButton = root.querySelector('[data-raid-clear-filters]');
    const results = root.querySelector('[data-raid-results]');
    const resultCount = root.querySelector('[data-raid-result-count]');
    const resultsBody = root.querySelector('[data-raid-results-body]');
    const rowTemplate = root.querySelector('[data-raid-row-template]');
    const skeleton = root.querySelector('[data-raid-skeleton]');
    const updatedTimes = root.querySelectorAll('[data-raid-updated]');

    if (
        !(form instanceof HTMLFormElement)
        || !(nationInput instanceof HTMLInputElement)
        || !(refreshButton instanceof HTMLButtonElement)
        || !(results instanceof HTMLElement)
        || !(resultCount instanceof HTMLElement)
        || !(resultsBody instanceof HTMLTableSectionElement)
        || !(rowTemplate instanceof HTMLTemplateElement)
    ) {
        return;
    }

    root.dataset.raidFinderBound = 'true';
    let targets = [];
    let requestInFlight = false;
    let updatedAt = null;
    let freshnessTimeout = null;
    let cancelCooldowns = [];

    const statePanels = new Map(
        Array.from(root.querySelectorAll('[data-raid-state-panel]'))
            .map((panel) => [panel.getAttribute('data-raid-state-panel'), panel]),
    );

    const setPanelMessage = (state, message) => {
        const panel = statePanels.get(state);
        const messageElement = panel?.querySelector('[data-async-state-message]');

        if (!(messageElement instanceof HTMLElement)) {
            return;
        }

        messageElement.textContent = message;
        messageElement.hidden = message === '';
    };

    const showState = (state, message = null, options = {}) => {
        statePanels.forEach((panel, panelState) => {
            panel.toggleAttribute('hidden', panelState !== state);
        });

        if (message !== null) {
            setPanelMessage(state, message);
        }

        skeleton?.toggleAttribute('hidden', state !== 'loading');
        results.toggleAttribute('hidden', !options.keepResults);
        root.dataset.raidState = state;

        const title = statePanels.get(state)?.querySelector('[data-async-state-title]')?.textContent?.trim();
        announce(message ? `${title}. ${message}` : title ?? 'Raid Finder updated.');
    };

    const syncFiltersToUrl = () => {
        const url = new URL(window.location.href);
        const formData = new FormData(form);

        url.searchParams.set('nation_id', nationInput.value);
        FILTER_KEYS.forEach((key) => {
            const value = String(formData.get(key) ?? '').trim();

            if (value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, value);
            }
        });

        window.history.replaceState({}, '', url);
    };

    const restoreFiltersFromUrl = () => {
        const params = new URLSearchParams(window.location.search);

        if (params.has('nation_id')) {
            nationInput.value = params.get('nation_id') ?? nationInput.value;
        }

        FILTER_KEYS.forEach((key) => {
            const control = form.elements.namedItem(key);

            if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement) {
                control.value = params.get(key) ?? control.value;
            }
        });
    };

    const filteredTargets = () => {
        const formData = new FormData(form);
        const search = String(formData.get('q') ?? '').trim().toLocaleLowerCase();
        const minimumCities = numericFilter(form, 'min_cities');
        const maximumCities = numericFilter(form, 'max_cities');
        const maximumWars = numericFilter(form, 'max_wars');
        const minimumLoot = numericFilter(form, 'min_loot');

        return targets.filter((target) => {
            const leader = String(target.nation?.leader_name ?? '').toLocaleLowerCase();
            const alliance = String(target.nation?.alliance?.name ?? '').toLocaleLowerCase();
            const cities = Number(target.nation?.num_cities ?? 0);
            const wars = Number(target.defensive_wars ?? 0);
            const loot = Number(target.value ?? 0);

            return (search === '' || leader.includes(search) || alliance.includes(search))
                && (minimumCities === null || cities >= minimumCities)
                && (maximumCities === null || cities <= maximumCities)
                && (maximumWars === null || wars <= maximumWars)
                && (minimumLoot === null || loot >= minimumLoot);
        });
    };

    const updateTimestamp = (value) => {
        updatedAt = value ? new Date(value) : new Date();
        const validDate = !Number.isNaN(updatedAt.getTime());

        updatedTimes.forEach((time) => {
            if (!(time instanceof HTMLTimeElement)) {
                return;
            }

            if (!validDate) {
                time.textContent = 'Unknown';
                time.removeAttribute('datetime');
                return;
            }

            time.dateTime = updatedAt.toISOString();
            time.textContent = updatedAt.toLocaleString();
        });

        if (freshnessTimeout !== null) {
            window.clearTimeout(freshnessTimeout);
        }

        if (validDate) {
            const remainingFreshTime = Math.max(0, FRESH_FOR_MS - (Date.now() - updatedAt.getTime()));
            freshnessTimeout = window.setTimeout(() => {
                if (targets.length > 0 && !requestInFlight) {
                    showState('stale', 'This page has been open for more than 30 minutes. Refresh before acting on a target.', {
                        keepResults: true,
                    });
                }
            }, remainingFreshTime);
        }
    };

    const renderRows = (visibleTargets) => {
        resultsBody.replaceChildren();

        visibleTargets.forEach((target) => {
            const fragment = rowTemplate.content.cloneNode(true);
            const nationLink = fragment.querySelector('[data-raid-nation-link]');
            const alliance = fragment.querySelector('[data-raid-alliance]');
            const cities = fragment.querySelector('[data-raid-cities]');
            const lastActive = fragment.querySelector('[data-raid-last-active]');
            const score = fragment.querySelector('[data-raid-score]');
            const wars = fragment.querySelector('[data-raid-wars]');
            const loot = fragment.querySelector('[data-raid-loot]');
            const lastBeige = fragment.querySelector('[data-raid-last-beige]');

            if (nationLink instanceof HTMLAnchorElement) {
                nationLink.href = `https://politicsandwar.com/nation/id=${encodeURIComponent(target.nation.id)}`;
                nationLink.textContent = target.nation.leader_name || `Nation ${target.nation.id}`;
            }

            if (alliance) alliance.textContent = target.nation.alliance?.name ?? 'None';
            if (cities) cities.textContent = formatNumber(target.nation.num_cities);
            if (score) score.textContent = formatNumber(target.nation.score, 2);
            if (wars) wars.textContent = formatNumber(target.defensive_wars);
            if (loot) loot.textContent = formatMoney(target.value);
            if (lastBeige) lastBeige.textContent = target.last_beige === null ? 'Not available' : formatMoney(target.last_beige);

            if (lastActive instanceof HTMLTimeElement) {
                const parsed = new Date(target.nation.last_active);
                lastActive.textContent = Number.isNaN(parsed.getTime()) ? 'Unknown' : parsed.toLocaleString();

                if (!Number.isNaN(parsed.getTime())) {
                    lastActive.dateTime = parsed.toISOString();
                }
            }

            resultsBody.appendChild(fragment);
        });

        resultCount.textContent = `${visibleTargets.length} ${visibleTargets.length === 1 ? 'result' : 'results'}`;
        window.initAppUi?.(results);
    };

    const applyFilters = (options = {}) => {
        syncFiltersToUrl();

        if (requestInFlight && targets.length === 0 && !options.requestCompleted) {
            return;
        }

        const visibleTargets = filteredTargets();

        renderRows(visibleTargets);

        if (targets.length === 0) {
            showState('empty', 'No eligible targets are available for this nation right now. Your filters were preserved.');
            return;
        }

        if (visibleTargets.length === 0) {
            showState('filtered_empty', 'The current filters exclude every eligible target. Adjust or clear them to continue.');
            return;
        }

        if (options.stale) {
            const reason = options.refreshState === 'rate_limited'
                ? 'The latest refresh was rate limited, so these are the most recent saved targets.'
                : 'The latest refresh failed, so these are the most recent saved targets.';
            showState('stale', reason, { keepResults: true });
            return;
        }

        showState('success', `Showing ${visibleTargets.length} eligible ${visibleTargets.length === 1 ? 'target' : 'targets'}.`, {
            keepResults: true,
        });
    };

    const startCooldown = (seconds) => {
        cancelCooldowns.forEach((cancel) => cancel());
        cancelCooldowns = [];

        const buttons = [refreshButton, ...root.querySelectorAll('[data-async-retry]')]
            .filter((button) => button instanceof HTMLButtonElement);

        buttons.forEach((button) => {
            const label = button.querySelector('[data-async-button-label]');
            const originalLabel = label?.textContent ?? button.textContent ?? 'Try again';
            cancelCooldowns.push(startRetryCountdown(button, seconds, (remaining) => {
                const nextLabel = remaining > 0 ? `Retry in ${remaining}s` : originalLabel;

                if (label) {
                    label.textContent = nextLabel;
                } else {
                    button.textContent = nextLabel;
                }
            }));
        });
    };

    const loadTargets = async () => {
        if (requestInFlight) {
            announce('Raid targets are already being refreshed.');
            return;
        }

        const nationId = Number(nationInput.value);
        if (!Number.isInteger(nationId) || nationId <= 0) {
            showState('error', 'Enter a valid positive nation ID. Your other filters are unchanged.');
            nationInput.focus();
            return;
        }

        requestInFlight = true;
        root.setAttribute('aria-busy', 'true');
        setButtonBusy(refreshButton, true, 'Refreshing…');

        if (targets.length === 0) {
            showState('loading', 'Checking Politics & War and the latest saved target data.');
        } else {
            announce('Refreshing raid targets.');
        }

        let retryAfter = null;

        try {
            const endpoint = `${root.dataset.raidFinderEndpoint}/${encodeURIComponent(nationId)}`;
            const { data, meta } = await requestJson(endpoint);

            if (!Array.isArray(data)) {
                throw new AsyncRequestError(`${getAppName()} returned an unexpected raid target response.`);
            }

            targets = data;
            updateTimestamp(meta.updatedAt);
            applyFilters({
                stale: meta.stale,
                refreshState: meta.state,
                requestCompleted: true,
            });
            retryAfter = meta.retryAfter;
        } catch (error) {
            const requestError = error instanceof AsyncRequestError
                ? error
                : new AsyncRequestError('Raid targets are temporarily unavailable.');
            const supportMessage = requestError.supportId ? ` Support ID: ${requestError.supportId}.` : '';

            retryAfter = requestError.retryAfter;
            showState(requestError.state, `${requestError.message}${supportMessage}`, { keepResults: targets.length > 0 });
        } finally {
            requestInFlight = false;
            root.removeAttribute('aria-busy');
            setButtonBusy(refreshButton, false);

            if (retryAfter !== null) {
                startCooldown(retryAfter);
            }
        }
    };

    restoreFiltersFromUrl();

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        syncFiltersToUrl();
        loadTargets();
    });
    form.addEventListener('input', (event) => {
        if (!(event.target instanceof HTMLInputElement) || event.target === nationInput) {
            return;
        }

        applyFilters();
    });
    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLSelectElement) {
            applyFilters();
        }
    });
    clearButton?.addEventListener('click', () => {
        FILTER_KEYS.forEach((key) => {
            const control = form.elements.namedItem(key);

            if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement) {
                control.value = '';
            }
        });
        applyFilters();
        announce('Raid Finder filters cleared.');
    });
    root.querySelectorAll('[data-async-retry]').forEach((button) => {
        button.addEventListener('click', loadTargets);
    });
    window.addEventListener('offline', () => {
        if (!requestInFlight) {
            showState('offline', 'Reconnect before refreshing. Your filters and any loaded targets remain available.', {
                keepResults: targets.length > 0,
            });
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (
            document.visibilityState === 'visible'
            && updatedAt instanceof Date
            && Date.now() - updatedAt.getTime() >= FRESH_FOR_MS
            && targets.length > 0
            && !requestInFlight
        ) {
            showState('stale', 'This page was paused and the target data is now older than 30 minutes. Refresh before acting.', {
                keepResults: true,
            });
        }
    });

    loadTargets();
};

export const initRaidFinders = (root = document) => {
    root.querySelectorAll('[data-raid-finder]').forEach(initializeRaidFinder);
};
