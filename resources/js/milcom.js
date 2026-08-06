const app = document.querySelector('[data-milcom-app]');

if (app) {
    const numberFormatter = new Intl.NumberFormat(document.documentElement.lang || 'en-US');
    const state = {
        detailController: null,
        progressPolling: false,
        progressRunId: null,
        progressTimer: null,
        progressButton: null,
        progressQueuedPolls: 0,
        nationNames: new Map([...app.querySelectorAll('[data-milcom-nation-id]')].map((element) => [
            String(element.dataset.milcomNationId),
            element.dataset.milcomNationName,
        ])),
        selectedObjectiveId: app.querySelector('[data-milcom-select-objective][aria-selected="true"]')?.dataset.objectiveId ?? null,
        selectedIncidentId: app.querySelector('[data-milcom-select-incident][aria-selected="true"]')?.dataset.incidentId ?? null,
    };

    const query = (selector, scope = app) => scope.querySelector(selector);
    const queryAll = (selector, scope = app) => [...scope.querySelectorAll(selector)];
    const headline = (value = '') => String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const statusLabel = (value = '') => ({
        covered_by_plan: 'Covered by plan',
        countering: 'Counter in progress',
        dispatching: 'Creating Discord room',
        dispatched: 'Sent to Discord',
        engaged: 'War started',
        generating: 'Building teams',
        pending: 'Waiting for review',
        review: 'Ready for review',
        hold: 'On hold',
    })[String(value)] ?? headline(value);
    const formatNumber = (value) => numberFormatter.format(Number.isFinite(Number(value)) ? Number(value) : 0);
    const formatMilitaryValue = (value) => value === null || value === undefined ? 'Not available' : formatNumber(value);
    const csrfToken = () => query('input[name="_token"]')?.value ?? document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    function createElement(tag, className = '', text = '') {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== '') {
            element.textContent = text;
        }

        return element;
    }

    function nationId(nation = {}, fallback = {}) {
        const value = nation.id ?? nation.nation_id ?? fallback.friendly_nation_id ?? fallback.target_nation_id;
        const parsed = Number(value);

        return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    }

    function createNationLink(nation, label, fallback = {}, className = '') {
        const id = nationId(nation, fallback);
        const element = createElement(id ? 'a' : 'span', className, label);

        if (id) {
            element.href = `https://politicsandwar.com/nation/id=${id}`;
            element.target = '_blank';
            element.rel = 'noopener noreferrer';
            element.dataset.milcomNationId = String(id);
            element.dataset.milcomNationName = label;
            element.classList.add('link', 'transition-colors');
            element.setAttribute('aria-label', `${label}, open nation on Politics & War in a new tab`);
            const newTab = createElement('span', 'sr-only', ' (opens in a new tab)');
            element.append(newTab);
        }

        return element;
    }

    function createMilitarySummary(nation = {}, variant = 'compact', label = 'Military') {
        const isTiles = variant === 'tiles';
        const summary = createElement(
            'dl',
            isTiles
                ? 'grid grid-cols-2 gap-px overflow-hidden rounded-md border border-base-300 bg-base-300 sm:grid-cols-4'
                : 'flex flex-wrap gap-x-4 gap-y-1 text-xs text-base-content/60',
        );
        summary.setAttribute('aria-label', label);

        [
            ['soldiers', 'Soldiers'],
            ['tanks', 'Tanks'],
            ['aircraft', 'Aircraft'],
            ['ships', 'Ships'],
        ].forEach(([key, label]) => {
            const item = createElement('div', isTiles ? 'bg-base-100 p-3' : 'flex items-baseline gap-1');
            const value = formatMilitaryValue(nation[key]);
            const valueElement = createElement('dd', `font-semibold tabular-nums text-base-content ${isTiles ? 'mt-1' : ''}`, value);
            valueElement.dataset.milcomMilitary = key;
            item.append(
                createElement('dt', isTiles ? 'nexus-stat-label' : '', label),
                valueElement,
            );
            summary.append(item);
        });

        return summary;
    }

    function setNationField(name, nation, label, fallback = {}) {
        const field = query(`[data-milcom-field="${name}"]`);

        if (!field) {
            return;
        }

        const id = nationId(nation, fallback);
        field.textContent = label;

        if (id) {
            field.href = `https://politicsandwar.com/nation/id=${id}`;
            field.target = '_blank';
            field.rel = 'noopener noreferrer';
            field.setAttribute('aria-label', `${label}, open nation on Politics & War in a new tab`);
            field.append(createElement('span', 'sr-only', ' (opens in a new tab)'));
        } else {
            field.removeAttribute('href');
            field.removeAttribute('target');
            field.removeAttribute('rel');
            field.removeAttribute('aria-label');
        }
    }

    function payloadData(payload) {
        return payload?.data ?? payload ?? {};
    }

    function listData(payload, key) {
        const data = payloadData(payload);
        const value = data?.[key] ?? data;

        if (Array.isArray(value)) {
            return value.slice(0, 50);
        }

        return Array.isArray(value?.data) ? value.data.slice(0, 50) : [];
    }

    function syncGeneration(payload) {
        const generationVersion = payload?.meta?.generation_version;

        if (!generationVersion) {
            return;
        }

        app.dataset.generationVersion = String(generationVersion);
        queryAll('input[name="generation_version"]').forEach((input) => {
            input.value = String(generationVersion);
        });
    }

    function setFormValue(form, name, value) {
        const field = query(`[name="${name}"]`, form);

        if (field) {
            field.value = value ?? '';
        }
    }

    function dateTimeLocal(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return String(value).slice(0, 16);
        }

        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
        return local.toISOString().slice(0, 16);
    }

    function announce(message) {
        const status = query('[data-milcom-status]');

        if (status) {
            status.textContent = '';
            window.requestAnimationFrame(() => {
                status.textContent = message;
            });
        }
    }

    function hideFeedback() {
        query('[data-milcom-feedback]')?.classList.add('hidden');
    }

    function showResult(message) {
        const result = query('[data-milcom-result]');

        if (result) {
            query('[data-milcom-result-message]', result).textContent = message;
            result.classList.remove('hidden');
        }

        announce(message);
    }

    function restoreResult() {
        const key = `milcom-result:${window.location.pathname}`;
        const scrollKey = `milcom-scroll:${window.location.pathname}`;
        const message = window.sessionStorage.getItem(key);
        const savedScrollPosition = window.sessionStorage.getItem(scrollKey);
        const scrollPosition = Number(savedScrollPosition);

        if (message) {
            window.sessionStorage.removeItem(key);
            showResult(message);
        }

        if (savedScrollPosition !== null && Number.isFinite(scrollPosition)) {
            window.sessionStorage.removeItem(scrollKey);
            window.requestAnimationFrame(() => window.scrollTo({ top: scrollPosition, behavior: 'auto' }));
        }
    }

    function refreshPlanWorkspace(message = 'The plan is up to date.') {
        const url = new URL(window.location.href);

        if (state.selectedObjectiveId) {
            url.searchParams.set('objective', state.selectedObjectiveId);
        }

        window.sessionStorage.setItem(`milcom-result:${window.location.pathname}`, message);
        window.sessionStorage.setItem(`milcom-scroll:${window.location.pathname}`, String(window.scrollY));
        window.history.replaceState({}, '', url);
        window.location.reload();
    }

    function setInspectorNavigationCurrent(targetId = 'target-readiness-title') {
        queryAll('[data-milcom-inspector-nav] [data-milcom-inspector-jump]').forEach((button) => {
            const isCurrent = button.dataset.milcomInspectorJump === targetId;
            button.setAttribute('aria-current', isCurrent ? 'true' : 'false');
        });
    }

    function scrollPlanInspectorToTop() {
        const scroller = query('[data-milcom-inspector-scroll]');

        scroller?.scrollTo({ top: 0, behavior: 'auto' });
        setInspectorNavigationCurrent();
    }

    function openPlanInspectorSheet() {
        const inspector = query('[data-milcom-inspector]');

        if (!inspector || window.matchMedia('(min-width: 80rem)').matches) {
            return;
        }

        inspector.dataset.milcomSheetOpen = 'true';
        inspector.setAttribute('role', 'dialog');
        inspector.setAttribute('aria-modal', 'true');
        document.body.classList.add('milcom-plan-inspector-open');
        window.requestAnimationFrame(() => query('[data-milcom-close-inspector]')?.focus({ preventScroll: true }));
    }

    function closePlanInspectorSheet({ restoreFocus = true } = {}) {
        const inspector = query('[data-milcom-inspector]');

        if (!inspector) {
            return;
        }

        delete inspector.dataset.milcomSheetOpen;
        inspector.removeAttribute('role');
        inspector.removeAttribute('aria-modal');
        document.body.classList.remove('milcom-plan-inspector-open');

        if (restoreFocus) {
            query('[data-milcom-select-objective][aria-selected="true"]')?.focus({ preventScroll: true });
        }
    }

    function scrollPlanInspectorToSection(targetId) {
        const scroller = query('[data-milcom-inspector-scroll]');
        const target = targetId ? query(`#${CSS.escape(targetId)}`, scroller) : null;
        const section = target?.closest('section') ?? target;

        if (!scroller || !section) {
            return;
        }

        const top = section.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
        scroller.scrollTo({
            top: Math.max(0, top),
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        setInspectorNavigationCurrent(targetId);
    }

    function setupPlanInspector() {
        queryAll('[data-milcom-inspector-jump]').forEach((button) => {
            button.addEventListener('click', () => scrollPlanInspectorToSection(button.dataset.milcomInspectorJump));
        });
        query('[data-milcom-close-inspector]')?.addEventListener('click', () => closePlanInspectorSheet());
        window.matchMedia('(min-width: 80rem)').addEventListener('change', (event) => {
            if (event.matches) {
                closePlanInspectorSheet({ restoreFocus: false });
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && query('[data-milcom-inspector]')?.dataset.milcomSheetOpen === 'true') {
                event.preventDefault();
                closePlanInspectorSheet();
            }
        });
    }

    function showFeedback(error, fallbackTitle = 'That action did not work') {
        const feedback = query('[data-milcom-feedback]');

        if (!feedback) {
            announce(error.message || fallbackTitle);
            return;
        }

        const blockerRows = Array.isArray(error.payload?.blockers) ? error.payload.blockers : [];
        const warningRows = Array.isArray(error.payload?.warnings) ? error.payload.warnings : [];
        const blockers = blockerRows
            .filter((blocker) => typeof blocker === 'string' || blocker.code !== 'warning_override_required')
            .map((blocker) => blocker.message ?? blocker.label ?? blocker)
            .join(' ');
        const warnings = warningRows
            .map((warning) => warning.message ?? warning.label ?? warning)
            .join(' ');
        const validationErrors = error.payload?.errors
            ? Object.values(error.payload.errors).flat().join(' ')
            : '';
        const isStale = blockerRows.some((blocker) => typeof blocker !== 'string' && blocker.code === 'stale_generation');
        const needsWarningReason = warningRows.length > 0
            && blockerRows.some((blocker) => typeof blocker !== 'string' && blocker.code === 'warning_override_required');
        const message = needsWarningReason
            ? `${warnings} Add a short reason below if you want to approve this target anyway.`
            : [blockers, warnings].filter(Boolean).join(' ')
                || validationErrors
                || error.message
                || 'Try again.';

        query('[data-milcom-feedback-title]', feedback).textContent = isStale
            ? 'This plan changed'
            : needsWarningReason
                ? 'Review this target'
                : fallbackTitle;
        query('[data-milcom-feedback-message]', feedback).textContent = message;
        feedback.classList.remove('hidden');
        feedback.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
        announce(query('[data-milcom-feedback-message]', feedback).textContent);
    }

    async function apiRequest(url, options = {}) {
        const { headers = {}, ...requestOptions } = options;
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                ...headers,
            },
            ...requestOptions,
        });
        const payload = response.status === 204 ? {} : await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(payload.message || `Request failed with status ${response.status}.`);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    }

    function setBusy(element, busy, label = 'Working') {
        if (!element) {
            return;
        }

        if (busy) {
            if (!element.dataset.originalHtml) {
                element.dataset.originalHtml = element.innerHTML;
                element.dataset.originalDisabled = element.disabled ? 'true' : 'false';
            }
            element.disabled = true;
            element.classList.add('btn-disabled');
            element.setAttribute('aria-busy', 'true');
            element.textContent = label;
            return;
        }

        element.disabled = element.dataset.originalDisabled === 'true';
        element.classList.remove('btn-disabled');
        element.removeAttribute('aria-busy');

        if (element.dataset.originalHtml) {
            element.innerHTML = element.dataset.originalHtml;
            delete element.dataset.originalHtml;
        }

        delete element.dataset.originalDisabled;
    }

    function formPayload(form) {
        const payload = {};

        for (const [rawKey, value] of new FormData(form)) {
            if (rawKey === '_token' || rawKey === '_method') {
                continue;
            }

            const isArray = rawKey.endsWith('[]');
            const isCsvIds = rawKey.endsWith('_ids_csv');
            const key = isCsvIds
                ? rawKey.slice(0, -4)
                : isArray
                    ? rawKey.slice(0, -2)
                    : rawKey;

            if (isCsvIds) {
                payload[key] = String(value)
                    .split(/[\s,]+/)
                    .map((item) => Number(item))
                    .filter((item) => Number.isInteger(item) && item > 0);
            } else if (isArray) {
                payload[key] = [...(payload[key] ?? []), value];
            } else {
                payload[key] = value;
            }
        }

        if (typeof payload.forum_tag_ids === 'string') {
            payload.forum_tag_ids = payload.forum_tag_ids.split(',').map((value) => value.trim()).filter(Boolean);
        }

        return payload;
    }

    function setupAlliancePickers() {
        const pickerRoots = queryAll('[data-alliance-picker]');

        if (pickerRoots.length === 0) {
            return;
        }

        const allianceCache = new Map();
        const pickers = new Map();
        const parseIds = (value) => [...new Set(String(value ?? '')
            .split(/[\s,]+/)
            .map((item) => Number(item))
            .filter((item) => Number.isInteger(item) && item > 0))];
        const allianceUrl = (alliance) => alliance.url || `https://politicsandwar.com/alliance/id=${alliance.id}`;
        const allianceInitials = (alliance) => {
            const source = alliance.acronym || alliance.name || `A${alliance.id}`;

            return String(source).replace(/[^a-z0-9]/gi, '').slice(0, 3).toUpperCase() || 'AA';
        };

        function createAllianceFlag(alliance, className) {
            const fallback = createElement('span', `${className} inline-grid shrink-0 place-items-center bg-base-200 text-[0.625rem] font-bold tracking-wide text-base-content/60`, allianceInitials(alliance));

            if (!alliance.flag) {
                return fallback;
            }

            const image = createElement('img', `${className} shrink-0 bg-base-200 object-cover`);
            image.src = alliance.flag;
            image.alt = '';
            image.loading = 'lazy';
            image.referrerPolicy = 'no-referrer';
            image.addEventListener('error', () => image.replaceWith(fallback), { once: true });

            return image;
        }

        function selectedOnOtherSide(picker, allianceId) {
            return [...pickers.values()].find((candidate) => candidate.side !== picker.side)?.selected.has(allianceId) ?? false;
        }

        function syncManualField(picker) {
            picker.manual.value = [...picker.selected.keys()].join(', ');
        }

        function selectionMeta(alliance) {
            const items = [`ID ${alliance.id}`];

            if (Number(alliance.rank) > 0) {
                items.push(`Rank ${formatNumber(alliance.rank)}`);
            }

            if (alliance.member_count !== null && alliance.member_count !== undefined) {
                items.push(`${formatNumber(alliance.member_count)} ${Number(alliance.member_count) === 1 ? 'member' : 'members'}`);
            }

            return items.join(' · ');
        }

        function renderSelections(picker) {
            const rows = [...picker.selected.values()].map((alliance) => {
                const hasConflict = selectedOnOtherSide(picker, Number(alliance.id));
                const row = createElement(
                    'div',
                    `flex items-center gap-3 rounded-md bg-base-100 px-3 py-2 shadow-sm ring-1 ${hasConflict ? 'ring-error' : 'ring-base-300'}`,
                );
                const body = createElement('div', 'min-w-0 grow');
                const heading = createElement('div', 'flex min-w-0 flex-wrap items-baseline gap-x-2');
                const link = createElement('a', 'link min-w-0 truncate font-semibold', alliance.name || `Alliance #${alliance.id}`);
                link.href = allianceUrl(alliance);
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.setAttribute('aria-label', `${alliance.name || `Alliance ${alliance.id}`}, open alliance on Politics & War in a new tab`);
                heading.append(link);

                if (alliance.acronym) {
                    heading.append(createElement('span', 'text-xs font-medium text-base-content/50', alliance.acronym));
                }

                body.append(heading, createElement('p', 'mt-0.5 text-xs text-base-content/60', selectionMeta(alliance)));

                if (hasConflict) {
                    body.append(createElement('p', 'mt-1 text-xs font-semibold text-error', `Also added to the ${picker.side === 'friendly' ? 'enemy' : 'friendly'} side`));
                }

                const remove = createElement('button', 'nexus-icon-button nexus-icon-button--compact btn btn-ghost btn-circle shrink-0', '×');
                remove.type = 'button';
                remove.dataset.allianceRemove = String(alliance.id);
                remove.setAttribute('aria-label', `Remove ${alliance.name || `alliance ${alliance.id}`} from the ${picker.side} side`);

                row.append(createAllianceFlag(alliance, 'h-8 w-12 rounded-sm'), body, remove);

                return row;
            });

            picker.selectedContainer.replaceChildren(...rows);
            picker.empty.classList.toggle('hidden', rows.length > 0);
            picker.count.textContent = formatNumber(rows.length);
        }

        function renderAllSelections() {
            pickers.forEach(renderSelections);
        }

        function closeResults(picker) {
            picker.results.classList.add('hidden');
            picker.input.setAttribute('aria-expanded', 'false');
            picker.input.removeAttribute('aria-activedescendant');
            picker.activeIndex = -1;
        }

        function showResults(picker) {
            picker.results.classList.remove('hidden');
            picker.input.setAttribute('aria-expanded', 'true');
        }

        function renderResultMessage(picker, message) {
            picker.options = [];
            picker.results.replaceChildren(createElement('p', 'px-3 py-4 text-center text-sm text-base-content/60', message));
            showResults(picker);
        }

        function optionAvailability(picker, allianceId) {
            if (picker.selected.has(allianceId)) {
                return 'Already added';
            }

            const otherPicker = [...pickers.values()].find((candidate) => candidate.side !== picker.side);

            if (otherPicker?.selected.has(allianceId)) {
                return `Already on the ${otherPicker.side === 'friendly' ? 'friendly' : 'enemy'} side`;
            }

            return null;
        }

        function setActiveOption(picker, nextIndex) {
            const enabledOptions = picker.options.filter((option) => !option.disabled);

            if (enabledOptions.length === 0) {
                picker.activeIndex = -1;
                picker.input.removeAttribute('aria-activedescendant');
                return;
            }

            picker.activeIndex = (nextIndex + enabledOptions.length) % enabledOptions.length;
            picker.options.forEach((option) => option.classList.remove('bg-primary/10'));
            const activeOption = enabledOptions[picker.activeIndex];
            activeOption.classList.add('bg-primary/10');
            activeOption.scrollIntoView({ block: 'nearest' });
            picker.input.setAttribute('aria-activedescendant', activeOption.id);
        }

        function renderAllianceResults(picker, alliances) {
            picker.activeIndex = -1;
            picker.options = alliances.map((alliance) => {
                allianceCache.set(Number(alliance.id), alliance);
                const unavailableReason = optionAvailability(picker, Number(alliance.id));
                const option = createElement(
                    'button',
                    `flex w-full items-center gap-3 rounded-md px-3 py-2 text-left transition-colors ${unavailableReason ? 'cursor-not-allowed opacity-50' : 'hover:bg-base-200 focus:bg-base-200 focus:outline-none'}`,
                );
                option.type = 'button';
                option.id = `${picker.side}-alliance-option-${alliance.id}`;
                option.role = 'option';
                option.dataset.allianceResultId = String(alliance.id);
                option.disabled = Boolean(unavailableReason);
                option.setAttribute('aria-selected', picker.selected.has(Number(alliance.id)) ? 'true' : 'false');

                const body = createElement('span', 'min-w-0 grow');
                const heading = createElement('span', 'flex min-w-0 flex-wrap items-baseline gap-x-2');
                heading.append(createElement('span', 'truncate font-semibold', alliance.name || `Alliance #${alliance.id}`));

                if (alliance.acronym) {
                    heading.append(createElement('span', 'text-xs font-medium text-base-content/50', alliance.acronym));
                }

                const details = createElement('span', 'mt-0.5 block text-xs text-base-content/60', selectionMeta(alliance));
                body.append(heading, details);
                option.append(createAllianceFlag(alliance, 'h-8 w-12 rounded-sm'), body);

                if (unavailableReason) {
                    option.append(createElement('span', 'shrink-0 text-xs font-semibold text-base-content/60', unavailableReason));
                } else {
                    option.append(createElement('span', 'badge badge-primary badge-soft badge-sm shrink-0', 'Add'));
                }

                return option;
            });

            if (picker.options.length === 0) {
                renderResultMessage(picker, 'No alliances found. Try a different name or ID.');
                return;
            }

            picker.results.replaceChildren(...picker.options);
            showResults(picker);
        }

        async function fetchAllianceResults(picker, search) {
            const cacheKey = search.toLocaleLowerCase();

            if (allianceCache.has(`search:${cacheKey}`)) {
                renderAllianceResults(picker, allianceCache.get(`search:${cacheKey}`));
                return;
            }

            picker.controller?.abort();
            picker.controller = new AbortController();
            picker.loading.classList.remove('hidden');

            try {
                const parameters = new URLSearchParams({ q: search, limit: '12' });
                const payload = payloadData(await apiRequest(`${app.dataset.apiBase}/alliances?${parameters}`, {
                    method: 'GET',
                    signal: picker.controller.signal,
                }));
                const alliances = Array.isArray(payload.alliances) ? payload.alliances : [];
                allianceCache.set(`search:${cacheKey}`, alliances);
                renderAllianceResults(picker, alliances);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    renderResultMessage(picker, 'Could not search alliances. Try again.');
                }
            } finally {
                picker.loading.classList.add('hidden');
            }
        }

        function queueSearch(picker) {
            window.clearTimeout(picker.searchTimer);
            const search = picker.input.value.trim();

            if (search.length < 2) {
                picker.controller?.abort();
                renderResultMessage(picker, search.length === 0 ? 'Start typing an alliance name, acronym, or ID.' : 'Type one more character to search.');
                return;
            }

            picker.searchTimer = window.setTimeout(() => fetchAllianceResults(picker, search), 250);
        }

        async function hydrateSelectedAlliances(picker) {
            const ids = [...picker.selected.keys()];

            for (let index = 0; index < ids.length; index += 20) {
                const chunk = ids.slice(index, index + 20);
                const parameters = new URLSearchParams({ ids: chunk.join(','), limit: '20' });

                try {
                    const payload = payloadData(await apiRequest(`${app.dataset.apiBase}/alliances?${parameters}`, { method: 'GET' }));
                    (payload.alliances ?? []).forEach((alliance) => {
                        allianceCache.set(Number(alliance.id), alliance);
                        if (picker.selected.has(Number(alliance.id))) {
                            picker.selected.set(Number(alliance.id), alliance);
                        }
                    });
                } catch {
                    // Keep the entered ID visible. Save validation will explain an unknown ID.
                }
            }

            renderAllSelections();
        }

        function syncPickerFromManualInput(picker) {
            const ids = parseIds(picker.manual.value);
            const nextSelected = new Map();

            ids.forEach((id) => {
                nextSelected.set(id, picker.selected.get(id) ?? allianceCache.get(id) ?? {
                    id,
                    name: `Alliance #${id}`,
                    acronym: null,
                    flag: null,
                    rank: null,
                    member_count: null,
                });
            });

            picker.selected = nextSelected;
            renderAllSelections();
            window.clearTimeout(picker.hydrateTimer);
            picker.hydrateTimer = window.setTimeout(() => hydrateSelectedAlliances(picker), 350);
        }

        pickerRoots.forEach((root) => {
            const side = root.dataset.allianceSide;
            const initialScript = query('[data-alliance-picker-initial]', root);
            let initialAlliances = [];

            try {
                initialAlliances = JSON.parse(initialScript?.textContent || '[]');
            } catch {
                initialAlliances = [];
            }

            const selected = new Map(initialAlliances.map((alliance) => [Number(alliance.id), alliance]));
            initialAlliances.forEach((alliance) => allianceCache.set(Number(alliance.id), alliance));
            pickers.set(side, {
                side,
                root,
                selected,
                selectedContainer: query('[data-alliance-selected]', root),
                empty: query('[data-alliance-empty]', root),
                count: query('[data-alliance-count]', root),
                input: query('[data-alliance-search]', root),
                loading: query('[data-alliance-loading]', root),
                results: query('[data-alliance-results]', root),
                manual: query(`[data-alliance-manual="${side}"]`),
                options: [],
                activeIndex: -1,
                searchTimer: null,
                hydrateTimer: null,
                controller: null,
            });
        });

        renderAllSelections();

        pickers.forEach((picker) => {
            picker.input.addEventListener('input', () => queueSearch(picker));
            picker.input.addEventListener('focus', () => queueSearch(picker));
            picker.input.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActiveOption(picker, picker.activeIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActiveOption(picker, picker.activeIndex - 1);
                } else if (event.key === 'Enter' && picker.activeIndex >= 0) {
                    event.preventDefault();
                    picker.options.filter((option) => !option.disabled)[picker.activeIndex]?.click();
                } else if (event.key === 'Escape') {
                    closeResults(picker);
                }
            });
            picker.results.addEventListener('click', (event) => {
                const option = event.target.closest('[data-alliance-result-id]');

                if (!option || option.disabled) {
                    return;
                }

                const allianceId = Number(option.dataset.allianceResultId);
                const alliance = allianceCache.get(allianceId);

                if (!alliance || selectedOnOtherSide(picker, allianceId)) {
                    return;
                }

                picker.selected.set(allianceId, alliance);
                syncManualField(picker);
                renderAllSelections();
                picker.input.value = '';
                closeResults(picker);
                picker.input.focus();
                announce(`${alliance.name} added to the ${picker.side} side.`);
            });
            picker.selectedContainer.addEventListener('click', (event) => {
                const button = event.target.closest('[data-alliance-remove]');

                if (!button) {
                    return;
                }

                const allianceId = Number(button.dataset.allianceRemove);
                const allianceName = picker.selected.get(allianceId)?.name ?? `Alliance ${allianceId}`;
                picker.selected.delete(allianceId);
                syncManualField(picker);
                renderAllSelections();
                announce(`${allianceName} removed from the ${picker.side} side.`);
            });
            picker.manual.addEventListener('input', () => syncPickerFromManualInput(picker));
            hydrateSelectedAlliances(picker);
        });

        document.addEventListener('click', (event) => {
            pickers.forEach((picker) => {
                if (!picker.root.contains(event.target)) {
                    closeResults(picker);
                }
            });
        });
    }

    function setField(name, value, scope = app) {
        const field = query(`[data-milcom-field="${name}"]`, scope);

        if (field) {
            field.textContent = value ?? 'Not available';
        }
    }

    function setStatusField(status, scope = app) {
        const field = query('[data-milcom-field="status"]', scope);

        if (!field) {
            return;
        }

        const tone = ['nexus-status--success', 'nexus-status--warning', 'nexus-status--error', 'nexus-status--info', 'nexus-status--neutral'];
        const success = ['approved', 'dispatched', 'engaged', 'completed', 'resolved', 'covered_by_plan', 'active'];
        const error = ['blocked', 'failed', 'conflict'];
        const info = ['countering', 'running', 'queued', 'dispatching', 'generating'];
        const neutral = ['hold'];
        field.classList.remove(...tone);
        field.classList.add(success.includes(status) ? 'nexus-status--success' : error.includes(status) ? 'nexus-status--error' : info.includes(status) ? 'nexus-status--info' : neutral.includes(status) ? 'nexus-status--neutral' : 'nexus-status--warning');
        field.textContent = statusLabel(status);
    }

    function updateRecommendationProgress(run, showTerminal = false) {
        const container = query('[data-milcom-recommendation-progress]');

        if (!container) {
            return;
        }

        const status = run?.status?.value ?? run?.status ?? '';
        const active = ['queued', 'running'].includes(status);
        const visible = active || (showTerminal && ['succeeded', 'failed'].includes(status));
        const progress = Math.max(0, Math.min(100, Number(run?.progress_percent ?? (status === 'succeeded' ? 100 : 0))));
        const labels = {
            queued: app.dataset.milcomApp === 'counter-queue' ? 'Counter team queued' : 'Team update queued',
            running: app.dataset.milcomApp === 'counter-queue' ? 'Building counter team' : 'Building teams',
            succeeded: 'Teams are ready',
            failed: 'Could not build teams',
        };
        const waitingForWorker = status === 'queued' && state.progressQueuedPolls >= 3;
        const note = query('[data-milcom-progress-note]', container);

        container.classList.toggle('hidden', !visible);
        container.classList.toggle('flex', visible);
        container.classList.toggle('alert-error', status === 'failed');
        container.classList.toggle('alert-success', status === 'succeeded');
        container.classList.toggle('alert-warning', waitingForWorker);
        container.classList.toggle('alert-info', active && !waitingForWorker);
        query('[data-milcom-progress-label]', container).textContent = waitingForWorker
            ? 'Waiting for the background worker'
            : labels[status] ?? 'Team-building progress';
        query('[data-milcom-progress-value]', container).textContent = `${formatNumber(progress)}%`;
        query('[data-milcom-progress-bar]', container).value = progress;

        if (note) {
            note.textContent = waitingForWorker
                ? 'This is still queued. Make sure the default queue worker is running.'
                : '';
            note.classList.toggle('hidden', !waitingForWorker);
        }
    }

    function stopRecommendationPolling() {
        if (state.progressTimer) {
            window.clearTimeout(state.progressTimer);
        }

        state.progressTimer = null;
        state.progressPolling = false;
        state.progressRunId = null;
        state.progressQueuedPolls = 0;
    }

    function releaseRecommendationButton() {
        if (state.progressButton) {
            setBusy(state.progressButton, false);
        }

        state.progressButton = null;
    }

    function pollRecommendation(runId) {
        if (!runId || (state.progressRunId === String(runId) && state.progressPolling)) {
            return;
        }

        stopRecommendationPolling();
        state.progressRunId = String(runId);
        state.progressPolling = true;

        const tick = async () => {
            try {
                const payload = payloadData(await apiRequest(`${app.dataset.apiBase}/recommendation-runs/${runId}`));
                const run = payload.recommendation_run ?? payload;
                const status = run.status?.value ?? run.status;
                state.progressQueuedPolls = status === 'queued' ? state.progressQueuedPolls + 1 : 0;
                updateRecommendationProgress(run, true);

                if (state.progressButton) {
                    setBusy(state.progressButton, true, status === 'running' ? 'Building…' : 'Queued…');
                }

                if (['queued', 'running'].includes(status)) {
                    state.progressTimer = window.setTimeout(tick, 1_000);
                    return;
                }

                state.progressTimer = null;
                state.progressPolling = false;
                state.progressRunId = null;
                state.progressQueuedPolls = 0;
                releaseRecommendationButton();

                if (status === 'succeeded') {
                    const isCounterQueue = app.dataset.milcomApp === 'counter-queue';

                    if (!isCounterQueue && app.dataset.milcomApp === 'plan-workspace') {
                        refreshPlanWorkspace('Teams are ready. Targets, warnings, and assignments are up to date.');
                        return;
                    }

                    const selected = query(`[data-${isCounterQueue ? 'milcom-select-incident' : 'milcom-select-objective'}][aria-selected="true"]`);

                    if (selected) {
                        await loadDetail(selected, isCounterQueue ? 'incident' : 'objective');
                    }

                    updateRecommendationProgress(run, true);
                    announce('Teams are ready.');
                    window.setTimeout(() => updateRecommendationProgress({}, false), 2_000);
                } else if (status === 'failed') {
                    showFeedback(new Error(run.failure_details?.message ?? 'Could not build teams.'));
                }
            } catch (error) {
                state.progressTimer = null;
                state.progressPolling = false;
                state.progressRunId = null;
                state.progressQueuedPolls = 0;
                releaseRecommendationButton();
                showFeedback(error, 'Could not check team progress');
            }
        };

        tick();
    }

    function watchRecommendation(recommendation) {
        const runId = recommendation?.run_id ?? recommendation?.id;
        const status = recommendation?.status?.value ?? recommendation?.status;

        if (runId && ['queued', 'running'].includes(status)) {
            updateRecommendationProgress(recommendation);
            pollRecommendation(runId);
            return;
        }

        if (state.progressPolling) {
            return;
        }

        if (!runId || state.progressRunId !== String(runId)) {
            stopRecommendationPolling();
        }

        updateRecommendationProgress({}, false);
    }

    function replaceList(name, items, renderer, emptyText, scope = app) {
        const container = query(`[data-milcom-list="${name}"]`, scope);

        if (!container) {
            return;
        }

        container.replaceChildren();

        if (!items.length) {
            const emptyTag = ['UL', 'OL'].includes(container.tagName) ? 'li' : 'p';
            container.append(createElement(emptyTag, 'py-4 text-sm text-base-content/60', emptyText));
            return;
        }

        items.forEach((item, index) => container.append(renderer(item, index)));
    }

    function renderTeamMember(member) {
        const nation = member.friendly ?? member.nation ?? member;
        const row = createElement('article', 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-3');
        const copy = createElement('div', 'min-w-0');
        const title = createElement('h4', 'truncate font-semibold');
        title.append(createNationLink(
            nation,
            nation.nation_name ?? member.nation_name ?? 'Unknown member',
            member,
        ));
        const slotText = member.offensive_capacity !== undefined
            ? `${member.offensive_wars ?? 0} active + ${member.reserved_slots ?? 0} reserved of ${member.offensive_capacity} slots`
            : `${nation.num_cities ?? nation.cities ?? 0} cities · ${member.offensive_slots_available ?? nation.offensive_slots_available ?? 'unknown'} slots free`;
        const detail = createElement('p', 'mt-1 text-xs text-base-content/55', slotText);
        const score = createElement('div', 'text-right');
        score.append(
            createElement('p', 'font-semibold tabular-nums', formatNumber(member.score ?? member.pair_score ?? 0)),
            createElement('p', 'text-xs text-base-content/55', member.confidence !== undefined ? `${formatNumber(member.confidence)}% confidence` : 'match score'),
        );
        const military = createMilitarySummary(nation, 'compact', 'Assigned nation military');
        military.classList.add('mt-2');
        copy.append(title, detail, military);
        row.append(copy, score);
        return row;
    }

    function renderBlocker(blocker) {
        const isString = typeof blocker === 'string';
        const hard = isString || blocker.hard !== false;
        const row = createElement('li', `flex items-start gap-2 text-sm ${hard ? 'text-error' : 'text-warning'}`);
        const marker = createElement('span', 'mt-1 shrink-0 font-bold', hard ? '×' : '!');
        marker.setAttribute('aria-hidden', 'true');
        row.append(marker, createElement('span', '', isString ? blocker : blocker.message ?? blocker.label ?? 'Blocked'));
        return row;
    }

    function renderWarning(warning) {
        const row = createElement('li', 'flex items-start gap-2 text-sm text-warning');
        const marker = createElement('span', 'mt-1 shrink-0 font-bold', '!');
        const copy = createElement('span');
        const message = typeof warning === 'string' ? warning : warning.message ?? warning.label ?? 'Warning';
        const warningNationId = Number(typeof warning === 'string' ? 0 : warning.context?.nation_id ?? 0);
        const warningNationName = typeof warning === 'string'
            ? null
            : warning.context?.nation_name ?? state.nationNames.get(String(warningNationId));
        marker.setAttribute('aria-hidden', 'true');

        if (warningNationId > 0) {
            const label = warningNationName || `Nation ${warningNationId}`;
            const suffix = message.startsWith('This nation')
                ? message.slice('This nation'.length)
                : `: ${message}`;
            copy.append(createNationLink({ id: warningNationId }, label), document.createTextNode(suffix));
        } else {
            copy.textContent = message;
        }

        row.append(marker, copy);
        return row;
    }

    function applyPlanWarningState(warnings, blockers = [], focus = false) {
        replaceList('warnings', warnings, renderWarning, 'No warnings.');
        const field = query('[data-milcom-plan-warning-override]');
        const textarea = query('textarea[name="override_reason"]', field);
        const hasHardBlocker = blockers.some((blocker) => {
            if (typeof blocker === 'string') {
                return true;
            }

            return blocker.code !== 'warning_override_required' && blocker.hard !== false;
        });
        const needsReason = warnings.length > 0 && !hasHardBlocker;

        field?.classList.toggle('hidden', !needsReason);
        textarea?.toggleAttribute('required', needsReason);

        if (focus && needsReason) {
            field.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'center',
            });
            window.requestAnimationFrame(() => textarea?.focus());
        }
    }

    function renderReason(reason) {
        const row = createElement('li', 'flex items-start gap-2');
        const marker = createElement('span', 'mt-2 size-1.5 shrink-0 rounded-full bg-primary');
        marker.setAttribute('aria-hidden', 'true');
        row.append(marker, createElement('span', '', typeof reason === 'string' ? reason : reason.message ?? reason.label ?? 'Match details'));
        return row;
    }

    function renderAlternative(alternative, index) {
        const row = createElement('article', 'flex flex-col gap-3 rounded-md border border-base-300 p-3 sm:flex-row sm:items-center sm:justify-between');
        const copy = createElement('div', 'min-w-0');
        const team = alternative.team ?? [];
        const members = createElement('ul', 'grid gap-2');
        team.forEach((member) => {
            const nation = member.friendly ?? member;
            const item = createElement('li', 'grid gap-1 sm:grid-cols-[minmax(8rem,1fr)_auto] sm:items-center sm:gap-4');
            const name = createElement('span', 'truncate font-semibold');
            name.append(createNationLink(nation, nation.nation_name ?? 'Member', member));
            const military = createMilitarySummary(nation, 'compact', 'Alternative nation military');
            military.classList.add('sm:justify-end');
            item.append(name, military);
            members.append(item);
        });
        if (team.length === 0) {
            members.append(createElement('li', 'font-semibold', 'Alternative team'));
        }
        copy.append(
            members,
            createElement('p', 'mt-1 text-xs text-base-content/55', `Team score ${Number(alternative.team_score ?? alternative.score ?? 0).toFixed(1)}`),
        );
        const button = createElement('button', 'btn btn-outline btn-sm', 'Use team');
        button.type = 'button';
        button.dataset.milcomUseAlternative = '';
        button.dataset.alternativeIndex = String(index);
        row.append(copy);

        if (app.dataset.operationStatus !== 'active') {
            row.append(button);
        }

        return row;
    }

    function renderPreflight(check) {
        const status = check.status ?? 'ready';
        const isReady = ['ready', 'pass', 'healthy'].includes(status);
        const row = createElement('div', 'flex items-start gap-2 rounded-md border border-base-300 p-3');
        const marker = createElement('span', `mt-0.5 shrink-0 font-bold ${isReady ? 'text-success' : status === 'warning' ? 'text-warning' : 'text-error'}`, isReady ? '✓' : status === 'warning' ? '!' : '×');
        marker.setAttribute('aria-hidden', 'true');
        const copy = createElement('div');
        copy.append(createElement('p', 'text-sm font-semibold', check.label ?? 'Final check'), createElement('p', 'mt-1 text-xs text-base-content/60', check.detail ?? headline(status)));
        row.append(marker, copy);
        return row;
    }

    function renderTimelineEvent(event, index) {
        const row = createElement('li', 'grid grid-cols-[auto_minmax(0,1fr)] gap-3 text-sm');
        const marker = createElement('span', `mt-1.5 size-2 rounded-full ${index === 0 ? 'bg-primary' : 'bg-base-content/25'}`);
        marker.setAttribute('aria-hidden', 'true');
        const copy = createElement('div');
        copy.append(createElement('p', 'font-semibold', event.title ?? headline(event.type ?? 'event')), createElement('p', 'mt-0.5 text-xs text-base-content/55', event.relative_time ?? event.created_at ?? event.occurred_at ?? 'Unknown'));
        row.append(marker, copy);
        return row;
    }

    function showInspectorLoading(loading) {
        query('[data-milcom-inspector-loading]')?.classList.toggle('hidden', !loading);

        if (loading) {
            query('[data-milcom-inspector-content]')?.classList.add('hidden');
            query('[data-milcom-inspector-empty]')?.classList.add('hidden');
        }
    }

    function showInspectorContent() {
        query('[data-milcom-inspector-loading]')?.classList.add('hidden');
        query('[data-milcom-inspector-empty]')?.classList.add('hidden');
        query('[data-milcom-inspector-content]')?.classList.remove('hidden');
    }

    function renderObjectiveDetail(payload) {
        const data = payloadData(payload);
        const objective = data.objective ?? data;
        const target = objective.target ?? data.target ?? {};
        const recommendation = objective.recommendation ?? data.recommendation ?? {};
        const team = recommendation.proposed_team ?? objective.assignments ?? data.team ?? [];
        const blockers = recommendation.blockers ?? objective.blockers ?? data.blockers ?? [];
        const warnings = recommendation.warnings ?? objective.warnings ?? data.warnings ?? [];
        const reasons = recommendation.explanations ?? objective.reasons ?? data.reasons ?? [];
        const alternatives = recommendation.alternatives ?? data.alternatives ?? [];
        const status = objective.status?.value ?? objective.status ?? 'review';
        const priority = objective.priority_tier?.value ?? objective.priority_tier ?? 'standard';
        const dispatch = objective.dispatch ?? {};
        const selectedChanged = String(state.selectedObjectiveId ?? '') !== String(objective.id ?? '');
        state.selectedObjectiveId = objective.id ?? null;
        state.nationNames = new Map(team.map((member) => {
            const nation = member.friendly ?? member.nation ?? member;
            const id = nationId(nation, member);

            return [String(id ?? ''), nation.nation_name ?? member.nation_name ?? `Nation ${id}`];
        }).filter(([id]) => id !== ''));
        syncGeneration({ meta: { generation_version: objective.operation?.generation_version ?? objective.generation_version } });

        setNationField('target_name', target, target.nation_name ?? objective.target_name ?? 'Selected target', objective);
        setNationField('leader_name', target, target.leader_name ?? 'Unknown leader', objective);
        setField('alliance_name', target.alliance?.name ?? objective.alliance_name ?? 'No alliance');
        setField('priority', headline(priority));
        setField('score', formatNumber(target.score));
        setField('cities', formatNumber(target.num_cities ?? target.cities));
        setField('soldiers', formatMilitaryValue(target.soldiers));
        setField('tanks', formatMilitaryValue(target.tanks));
        setField('aircraft', formatMilitaryValue(target.aircraft));
        setField('ships', formatMilitaryValue(target.ships));
        setField('freshness', recommendation.snapshot_relative_time ?? recommendation.snapshot_at ?? objective.snapshot_at ?? 'Unknown');
        setField('staffed_depth', formatNumber(team.length));
        setField('desired_depth', formatNumber(objective.desired_team_depth ?? 3));
        setStatusField(priority === 'hold' ? 'hold' : status);
        watchRecommendation(recommendation);
        replaceList('team', team.slice(0, 3), renderTeamMember, 'No eligible team found.');
        replaceList('blockers', blockers, renderBlocker, 'No blockers.');
        if (selectedChanged) {
            const reason = query('[data-milcom-plan-warning-override] textarea[name="override_reason"]');
            reason && (reason.value = '');
        }
        applyPlanWarningState(warnings, blockers);
        replaceList('reasons', reasons.slice(0, 8), renderReason, 'Regenerate teams to see match reasons.');
        replaceList('alternatives', alternatives.slice(0, 3), renderAlternative, 'No other eligible team found.');

        const approvalForm = query('[data-milcom-command="approve-objective"]');
        if (approvalForm) {
            approvalForm.action = `${app.dataset.apiBase}/objectives/${objective.id}/approve`;
            const button = query('button[type="submit"]', approvalForm);
            const canApprove = priority !== 'hold' && ['pending', 'review', 'blocked'].includes(status);
            approvalForm.classList.toggle('hidden', !canApprove);
            approvalForm.classList.toggle('inline-flex', canApprove);
            button.disabled = !canApprove
                || blockers.some((blocker) => typeof blocker === 'string' || blocker.hard !== false);
        }

        const dispatchForm = query('[data-milcom-dispatch-objective]');
        if (dispatchForm) {
            const canDispatch = status === 'approved' && app.dataset.operationStatus === 'active';
            dispatchForm.action = `${app.dataset.apiBase}/objectives/${objective.id}/dispatch`;
            dispatchForm.classList.toggle('hidden', !canDispatch);
            dispatchForm.classList.toggle('inline-flex', canDispatch);
        }

        const retryForm = query('[data-milcom-retry-dispatch]');
        if (retryForm) {
            retryForm.action = `${app.dataset.apiBase}/objectives/${objective.id}/dispatch/retry`;
            retryForm.classList.toggle('hidden', dispatch.status !== 'failed');
            retryForm.classList.toggle('flex', dispatch.status === 'failed');
        }

        const updateForm = query('[data-milcom-command="update-objective"]');
        if (updateForm) {
            updateForm.action = `${app.dataset.apiBase}/objectives/${objective.id}`;
            setFormValue(updateForm, 'priority_tier', objective.priority_tier?.value ?? objective.priority_tier);
            setFormValue(updateForm, 'war_type', objective.war_type);
            setFormValue(updateForm, 'minimum_team_depth', objective.minimum_team_depth);
            setFormValue(updateForm, 'desired_team_depth', objective.desired_team_depth);
            setFormValue(updateForm, 'deadline_at', dateTimeLocal(objective.deadline_at));
            setFormValue(updateForm, 'war_reason', objective.war_reason);
        }

        const manualForm = query('[data-milcom-command="manual-assignment"]');
        if (manualForm) {
            manualForm.action = `${app.dataset.apiBase}/objectives/${objective.id}/assignments/manual`;
        }

        const releaseForm = query('[data-milcom-release-form]');
        const assignmentSelect = query('[data-milcom-assignment-select]', releaseForm);
        if (assignmentSelect) {
            const options = team
                .filter((assignment) => assignment.id)
                .map((assignment) => {
                    const option = createElement('option', '', assignment.friendly?.nation_name ?? `Assigned nation #${assignment.id}`);
                    option.value = String(assignment.id);
                    return option;
                });
            assignmentSelect.replaceChildren(...options);
        }

        const cancelForm = query('[data-milcom-command="cancel-objective"]');
        if (cancelForm) {
            cancelForm.action = `${app.dataset.apiBase}/objectives/${objective.id}/cancel`;
        }

        const staffingLocked = !['pending', 'review', 'blocked'].includes(status);
        [updateForm, manualForm].filter(Boolean).forEach((form) => {
            queryAll('input, select, textarea, button', form).forEach((control) => {
                control.disabled = staffingLocked && control.name !== 'generation_version';
            });
        });
        query('button[type="submit"]', releaseForm)?.toggleAttribute('disabled', staffingLocked || !assignmentSelect?.value);
        query('button[type="submit"]', cancelForm)?.toggleAttribute('disabled', ['engaged', 'completed', 'cancelled', 'expired'].includes(status));

        showInspectorContent();
    }

    function renderIncidentDetail(payload) {
        const data = payloadData(payload);
        const incident = data.incident ?? data;
        const objective = incident.objective ?? data.objective ?? {};
        const objectiveStatus = objective.status?.value ?? objective.status ?? 'review';
        const dispatch = objective.dispatch ?? {};
        const recommendation = objective.recommendation ?? incident.recommendation ?? data.recommendation ?? {};
        const aggressor = objective.target ?? incident.aggressor ?? incident.attacker ?? data.aggressor ?? {};
        const defender = incident.attacked ?? incident.defender ?? data.defender ?? {};
        const team = recommendation.proposed_team ?? objective.assignments ?? data.team ?? [];
        const blockers = recommendation.blockers ?? objective.blockers ?? data.blockers ?? [];
        const warnings = recommendation.warnings ?? objective.warnings ?? data.warnings ?? [];
        const alternatives = recommendation.alternatives ?? data.alternatives ?? [];
        const preflight = objective.preflight ?? data.preflight ?? [];
        const timeline = incident.events ?? objective.events ?? data.events ?? [];
        const hardBlockers = blockers.filter((blocker) => typeof blocker === 'string' || blocker.hard !== false);
        state.selectedObjectiveId = objective.id ?? null;
        state.nationNames = new Map(team.map((member) => {
            const nation = member.friendly ?? member.nation ?? member;
            const id = nationId(nation, member);

            return [String(id ?? ''), nation.nation_name ?? member.nation_name ?? `Nation ${id}`];
        }).filter(([id]) => id !== ''));
        syncGeneration({ meta: { generation_version: objective.operation?.generation_version ?? objective.generation_version } });

        setNationField('aggressor_name', aggressor, aggressor.nation_name ?? incident.aggressor_name ?? 'Selected incident', { target_nation_id: incident.aggressor_nation_id });
        setNationField('defender_name', defender, defender.nation_name ?? incident.defender_name ?? 'Friendly nation', { target_nation_id: incident.attacked_nation_id });
        setField('war_id', incident.war_id ?? 'Not available');
        setField('detected_at', incident.detected_relative_time ?? incident.detected_at ?? 'Unknown');
        setField('score', formatNumber(aggressor.score));
        setField('cities', formatNumber(aggressor.num_cities ?? aggressor.cities));
        setField('soldiers', formatMilitaryValue(aggressor.soldiers));
        setField('tanks', formatMilitaryValue(aggressor.tanks));
        setField('aircraft', formatMilitaryValue(aggressor.aircraft));
        setField('ships', formatMilitaryValue(aggressor.ships));
        setField('team_depth', formatNumber(team.length));
        setField('room_name', `counter-${String(aggressor.nation_name ?? 'target').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')}`);
        setStatusField(incident.handling_state?.value ?? incident.status?.value ?? incident.status ?? 'new');
        watchRecommendation(recommendation);
        replaceList('team', team.slice(0, 3), renderTeamMember, 'No eligible team found yet.');
        replaceList('blockers', blockers, renderBlocker, 'No blockers.');
        replaceList('warnings', warnings, renderWarning, 'No warnings.');
        replaceList('alternatives', alternatives.slice(0, 3), renderAlternative, 'No other eligible team found.');
        replaceList('preflight', preflight, renderPreflight, 'Final checks will appear when the team is ready.');
        replaceList('timeline', timeline.slice(0, 20), renderTimelineEvent, 'New events will appear here.');

        const overrideField = query('[data-milcom-override-field]');
        overrideField?.classList.toggle('hidden', warnings.length === 0);
        query('textarea[name="override_reason"]', overrideField)?.toggleAttribute('required', warnings.length > 0);

        const dispatchForm = query('[data-milcom-command="approve-dispatch-counter"]');
        const canDispatch = ['pending', 'review', 'blocked', 'approved'].includes(objectiveStatus)
            && dispatch.status !== 'failed';
        if (dispatchForm) {
            dispatchForm.action = `${app.dataset.apiBase}/objectives/${objective.id ?? 0}/dispatch`;
            const generationInput = query('input[name="generation_version"]', dispatchForm);
            const dispatchButton = query('button[type="submit"]', dispatchForm);
            if (generationInput) {
                generationInput.value = objective.operation?.generation_version ?? objective.generation_version ?? app.dataset.generationVersion ?? 1;
            }
            dispatchForm.classList.toggle('hidden', !canDispatch);
            dispatchButton.disabled = !objective.id || !canDispatch || hardBlockers.length > 0;
        }

        const retryForm = query('[data-milcom-retry-dispatch]');
        if (retryForm) {
            retryForm.action = `${app.dataset.apiBase}/objectives/${objective.id ?? 0}/dispatch/retry`;
            retryForm.classList.toggle('hidden', dispatch.status !== 'failed');
            retryForm.classList.toggle('flex', dispatch.status === 'failed');
            setField('dispatch_error', dispatch.error ?? 'You can safely retry this Discord room.', retryForm);
        }

        const dispatchState = query('[data-milcom-dispatch-state]');
        dispatchState?.classList.toggle(
            'hidden',
            canDispatch || dispatch.status === 'failed' || !objective.id,
        );
        dispatchState?.classList.toggle(
            'flex',
            !canDispatch && dispatch.status !== 'failed' && Boolean(objective.id),
        );
        setField('dispatch_status', headline(dispatch.status || objectiveStatus));

        const cancelForm = query('[data-milcom-command="cancel-objective"]');
        if (cancelForm) {
            cancelForm.action = `${app.dataset.apiBase}/objectives/${objective.id ?? 0}/cancel`;
            query('button[type="submit"]', cancelForm)?.toggleAttribute(
                'disabled',
                !objective.id || ['engaged', 'completed', 'cancelled', 'expired'].includes(objectiveStatus),
            );
        }

        showInspectorContent();
    }

    function selectRovingItem(button, selector, idKey) {
        queryAll(selector).forEach((item) => {
            const selected = item === button;
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
            item.tabIndex = selected ? 0 : -1;
            const row = item.closest('[data-milcom-objective-row]') ?? item;
            row.classList.toggle('bg-primary/7', selected);
        });
        state[idKey] = button.dataset.objectiveId ?? button.dataset.incidentId;
    }

    async function loadDetail(button, type) {
        const isObjective = type === 'objective';
        const id = isObjective ? button.dataset.objectiveId : button.dataset.incidentId;
        const template = app.dataset[isObjective ? 'objectiveDetailTemplate' : 'incidentDetailTemplate'];

        if (!id || !template) {
            return;
        }

        state.detailController?.abort();
        state.detailController = new AbortController();
        hideFeedback();
        selectRovingItem(button, isObjective ? '[data-milcom-select-objective]' : '[data-milcom-select-incident]', isObjective ? 'selectedObjectiveId' : 'selectedIncidentId');
        if (isObjective) {
            openPlanInspectorSheet();
            scrollPlanInspectorToTop();
        }
        showInspectorLoading(true);

        try {
            const payload = await apiRequest(template.replace('{id}', encodeURIComponent(id)), { signal: state.detailController.signal });
            isObjective ? renderObjectiveDetail(payload) : renderIncidentDetail(payload);
            if (isObjective) {
                scrollPlanInspectorToTop();
            }
            const url = new URL(window.location.href);
            url.searchParams.set(isObjective ? 'objective' : 'incident', id);
            window.history.replaceState({}, '', url);
            announce(`${isObjective ? 'Target' : 'Counter'} loaded.`);
        } catch (error) {
            if (error.name !== 'AbortError') {
                showInspectorLoading(false);
                query('[data-milcom-inspector-content]')?.classList.remove('hidden');
                showFeedback(error, `Could not load this ${isObjective ? 'target' : 'counter'}`);
            }
        }
    }

    function setupRovingNavigation(selector, type) {
        queryAll(selector).forEach((button) => {
            button.addEventListener('click', () => loadDetail(button, type));
            button.addEventListener('keydown', (event) => {
                const items = queryAll(selector).filter((item) => !item.closest('[hidden]'));
                const currentIndex = items.indexOf(button);
                let nextIndex = currentIndex;

                if (['ArrowDown', 'j', 'J'].includes(event.key)) {
                    nextIndex = Math.min(items.length - 1, currentIndex + 1);
                } else if (['ArrowUp', 'k', 'K'].includes(event.key)) {
                    nextIndex = Math.max(0, currentIndex - 1);
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = items.length - 1;
                } else {
                    return;
                }

                event.preventDefault();
                items[nextIndex]?.focus();
                items[nextIndex]?.click();
            });
        });
    }

    function updateBatchSelection() {
        const checkboxes = queryAll('[data-milcom-objective-checkbox]');
        const selected = queryAll('[data-milcom-objective-checkbox]:checked');
        const actions = query('[data-milcom-batch-actions]');
        const count = query('[data-milcom-selected-count]');
        count && (count.textContent = formatNumber(selected.length));
        actions?.classList.toggle('hidden', selected.length === 0);
        actions?.classList.toggle('flex', selected.length > 0);
        queryAll('[data-milcom-select-all]').forEach((selectAll) => {
            selectAll.checked = checkboxes.length > 0 && selected.length === checkboxes.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
        });
    }

    async function submitCommand(form, submitter) {
        hideFeedback();
        const batchCommand = submitter?.dataset.milcomBatchCommand;
        const busyLabel = batchCommand === 'dispatch'
            || ['dispatch-objective', 'dispatch-ready'].includes(form.dataset.milcomCommand)
            ? 'Creating rooms…'
            : form.dataset.milcomCommand === 'deliver-in-game'
                ? 'Queueing messages…'
            : batchCommand === 'approve-eligible'
                ? 'Approving…'
                : 'Working…';
        setBusy(submitter, true, busyLabel);
        let recommendationQueued = false;

        try {
            const requestPayload = formPayload(form);
            let endpoint = submitter?.getAttribute('formaction') || form.action;

            if (form.hasAttribute('data-milcom-release-form')) {
                const assignmentId = requestPayload.assignment_id;

                if (!assignmentId || !state.selectedObjectiveId) {
                    throw new Error('Select a nation to remove.');
                }

                endpoint = form.dataset.releaseTemplate
                    .replace('{objective}', state.selectedObjectiveId)
                    .replace('{assignment}', assignmentId);
                delete requestPayload.assignment_id;
            }

            const payload = await apiRequest(endpoint, {
                method: (submitter?.getAttribute('formmethod') || form.dataset.method || form.getAttribute('method') || 'POST').toUpperCase(),
                body: JSON.stringify(requestPayload),
            });
            syncGeneration(payload);
            const message = payload.message ?? 'Action complete.';
            const recommendationRun = payloadData(payload).recommendation_run;

            if (form.dataset.milcomCommand === 'dismiss-raid-alert') {
                form.closest('[data-milcom-exception-row]')?.remove();
                const hasRemainingExceptions = query('[data-milcom-exception-row]') !== null;
                query('[data-milcom-exception-empty]')?.classList.toggle('hidden', hasRemainingExceptions);
                announce(message);
                return;
            }

            if (recommendationRun?.id) {
                recommendationQueued = true;
                state.progressButton = submitter;
                setBusy(submitter, true, 'Queued…');
                watchRecommendation(recommendationRun);
            }
            form.dispatchEvent(new CustomEvent('milcom:command-success', { bubbles: true, detail: payload }));

            if (form.hasAttribute('data-success-redirect') && payload.links?.self) {
                window.location.assign(payload.links.self);
                return;
            }

            if (app.dataset.milcomApp === 'plan-workspace' && !recommendationRun?.id) {
                refreshPlanWorkspace(message);
                return;
            }

            if (form.matches('[data-milcom-batch-form]')) {
                queryAll('[data-milcom-objective-checkbox]:checked', form).forEach((checkbox) => {
                    checkbox.checked = false;
                });
                updateBatchSelection();
            }

            if (batchCommand === 'approve-eligible') {
                window.sessionStorage.setItem(`milcom-result:${window.location.pathname}`, message);
                window.location.reload();
                return;
            }

            const isCounterQueue = app.dataset.milcomApp === 'counter-queue';
            const selected = query(`[data-${isCounterQueue ? 'milcom-select-incident' : 'milcom-select-objective'}][aria-selected="true"]`);

            if (selected && !recommendationRun?.id) {
                await loadDetail(selected, isCounterQueue ? 'incident' : 'objective');
            }

            announce(message);
        } catch (error) {
            releaseRecommendationButton();
            showFeedback(error);

            if (app.dataset.milcomApp === 'plan-workspace' && Array.isArray(error.payload?.warnings)) {
                applyPlanWarningState(
                    error.payload.warnings,
                    Array.isArray(error.payload?.blockers) ? error.payload.blockers : [],
                    true,
                );
            }
        } finally {
            if (!recommendationQueued) {
                setBusy(submitter, false);
            }
        }
    }

    function setupCommands() {
        queryAll('form[data-milcom-command]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                event.stopPropagation();
                const submitter = event.submitter ?? query('button[type="submit"]', form);

                if (form.dataset.confirm) {
                    const confirmed = typeof window.NexusConfirm === 'function'
                        ? await window.NexusConfirm(form.dataset.confirm, {
                            title: form.dataset.confirmTitle,
                            label: form.dataset.confirmLabel,
                            tone: form.dataset.confirmTone,
                        })
                        : window.confirm(form.dataset.confirm);

                    if (!confirmed) {
                        return;
                    }
                }

                submitCommand(form, submitter);
            });
        });

        query('[data-milcom-batch-form]')?.addEventListener('submit', (event) => {
            if (!event.submitter?.matches('[data-milcom-batch-command]')) {
                return;
            }

            event.preventDefault();

            if (event.submitter.dataset.milcomBatchCommand === 'approve-reviewable') {
                const details = query('[data-milcom-approve-warnings]', event.currentTarget);
                const reason = query('textarea[name="override_reason"]', details);

                if (!reason || reason.value.trim().length < 10) {
                    details && (details.open = true);
                    reason?.focus();
                    showFeedback(new Error('Add a short officer reason before approving targets with warnings.'));
                    return;
                }
            }

            submitCommand(event.currentTarget, event.submitter);
        });
    }

    async function refreshDashboard() {
        const endpoint = app.dataset.summaryEndpoint;

        if (!endpoint) {
            return;
        }

        const payload = payloadData(await apiRequest(endpoint));
        const summary = payload.summary ?? payload;
        queryAll('[data-milcom-value]').forEach((element) => {
            const value = summary[element.dataset.milcomValue];
            if (value !== undefined) {
                element.textContent = formatNumber(value);
            }
        });
        window.location.reload();
    }

    async function refreshCurrentPage(button) {
        setBusy(button, true, 'Refreshing…');
        hideFeedback();

        try {
            if (app.dataset.milcomApp === 'dashboard') {
                await refreshDashboard();
            } else if (app.dataset.milcomApp === 'counter-queue') {
                await apiRequest(app.dataset.incidentsEndpoint);
                window.location.reload();
            } else {
                const selected = query('[data-milcom-select-objective][aria-selected="true"]');
                selected?.click();
            }
        } catch (error) {
            showFeedback(error, 'Could not refresh Milcom');
            setBusy(button, false);
        }
    }

    function setupAlternativeActions() {
        app.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-milcom-use-alternative]');

            if (!button) {
                return;
            }

            const objectiveId = state.selectedObjectiveId
                ?? query('[data-milcom-command="approve-dispatch-counter"]')?.action.match(/objectives\/(\d+)/)?.[1];

            if (!objectiveId) {
                announce('Select a target before changing its team.');
                return;
            }

            setBusy(button, true);
            try {
                await apiRequest(`${app.dataset.apiBase}/objectives/${objectiveId}/assignments`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        alternative_index: Number(button.dataset.alternativeIndex),
                        generation_version: Number(app.dataset.generationVersion ?? 1),
                        lock: true,
                    }),
                });
                if (app.dataset.milcomApp === 'plan-workspace') {
                    refreshPlanWorkspace('Alternative team selected.');
                    return;
                }

                announce('Alternative team selected.');
                query('[data-milcom-select-incident][aria-selected="true"]')?.click();
            } catch (error) {
                showFeedback(error, 'Could not select that team');
            } finally {
                setBusy(button, false);
            }
        });
    }

    function renderLegacyDetail(container, payload) {
        const data = payloadData(payload);
        const title = createElement('h3', 'font-semibold');
        const titleText = data.name ?? data.aggressor?.nation_name ?? 'Legacy record';
        title.append(data.aggressor?.id
            ? createNationLink(data.aggressor, titleText)
            : document.createTextNode(titleText));
        const description = createElement('p', 'mt-1 max-w-3xl text-sm text-base-content/65', data.summary ?? data.description ?? 'This older record is view only.');
        const facts = createElement('dl', 'mt-4 flex flex-wrap gap-x-6 gap-y-3');
        const values = [
            ['Status', headline(data.status ?? 'archived')],
            ['Targets', formatNumber(data.targets_count ?? 0)],
            ['Assigned nations', formatNumber(data.assignments_count ?? 0)],
            ['Discord room', data.discord_channel_id ?? 'Not recorded'],
        ];
        values.forEach(([label, value]) => {
            const item = createElement('div');
            item.append(createElement('dt', 'nexus-stat-label', label), createElement('dd', 'mt-1 text-sm font-semibold', value));
            facts.append(item);
        });
        container.replaceChildren(title, description, facts);
    }

    function setupLegacyDetails() {
        queryAll('[data-milcom-load-legacy]').forEach((link) => {
            link.addEventListener('click', async (event) => {
                if (!link.dataset.detailEndpoint) {
                    return;
                }

                event.preventDefault();
                const row = link.closest('[data-milcom-legacy-row]')?.nextElementSibling;
                const container = query('[data-milcom-legacy-detail]', row);

                if (!row || !container) {
                    window.location.assign(link.href);
                    return;
                }

                if (!row.classList.contains('hidden') && container.childElementCount > 0) {
                    row.classList.add('hidden');
                    link.textContent = 'View details';
                    return;
                }

                row.classList.remove('hidden');
                container.replaceChildren(createElement('p', 'text-sm text-base-content/60', 'Loading history…'));
                setBusy(link, true, 'Loading…');
                let expanded = false;
                try {
                    renderLegacyDetail(container, await apiRequest(link.dataset.detailEndpoint));
                    expanded = true;
                } catch (error) {
                    row.classList.add('hidden');
                    showFeedback(error, 'Could not load this record');
                } finally {
                    setBusy(link, false);
                    if (expanded) {
                        link.textContent = 'Hide details';
                    }
                }
            });
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey || /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName)) {
            return;
        }

        const search = query('[data-milcom-search]');
        if (search) {
            event.preventDefault();
            search.focus();
            search.select();
        }
    });

    queryAll('[data-milcom-objective-checkbox]').forEach((checkbox) => checkbox.addEventListener('change', updateBatchSelection));
    queryAll('[data-milcom-select-all]').forEach((selectAll) => selectAll.addEventListener('change', () => {
        queryAll('[data-milcom-objective-checkbox]').forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });
        updateBatchSelection();
    }));
    queryAll('[data-milcom-refresh]').forEach((button) => button.addEventListener('click', () => refreshCurrentPage(button)));
    setupRovingNavigation('[data-milcom-select-objective]', 'objective');
    setupRovingNavigation('[data-milcom-select-incident]', 'incident');
    setupAlliancePickers();
    setupPlanInspector();
    setupCommands();
    setupAlternativeActions();
    setupLegacyDetails();
    updateBatchSelection();
    restoreResult();
}
