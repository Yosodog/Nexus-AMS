import { AsyncRequestError, announce, requestJson, setButtonBusy } from './async-ui';

const STORAGE_KEY = 'nexus:raid-finder:filters';
const FILTER_FIELDS = ['min_expected_net', 'min_inactive_days', 'beige_within_turns', 'alliance_scope', 'beatable_only', 'hide_claimed'];
const RESOURCES = ['money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'];
const COMPONENTS = {
    gross_loot: 'Gross loot',
    nation_loot: 'Victory loot',
    ground_loot: 'Ground loot',
    bank_loot: 'Alliance bank loot',
    bounty: 'Bounty',
    consumables: 'Munitions & gasoline',
    military_losses: 'Unit losses',
    infrastructure_losses: 'Infrastructure losses',
    counter_risk: 'Counter risk',
};
const CONFIDENCE_BADGES = { high: 'badge-success', medium: 'badge-warning', low: 'badge-ghost' };

const currency = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
const amount = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });
const percent = new Intl.NumberFormat(undefined, { style: 'percent', maximumFractionDigits: 0 });
const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

const label = (key) => key.charAt(0).toUpperCase() + key.slice(1).replaceAll('_', ' ');

const relativeTime = (iso) => {
    const timestamp = Date.parse(iso ?? '');

    if (Number.isNaN(timestamp)) {
        return 'Unknown';
    }

    const seconds = Math.round((timestamp - Date.now()) / 1000);
    const units = [['day', 86400], ['hour', 3600], ['minute', 60]];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return relative.format(seconds, 'second');
};

const setTime = (element, iso) => {
    element.dataset.timestamp = iso ?? '';
    element.dateTime = iso ?? '';
    element.textContent = relativeTime(iso);
};

const readStoredFilters = () => {
    try {
        return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}') ?? {};
    } catch {
        return {};
    }
};

const storeFilters = (values) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(values));
    } catch {
        // Storage may be unavailable in private windows; filters still work for this page view.
    }
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const cell = (element, text) => {
    element.textContent = text;

    return element;
};

class RaidFinder {
    constructor(root) {
        this.root = root;
        this.form = root.querySelector('[data-raid-filters]');
        this.rowsBody = root.querySelector('[data-raid-rows]');
        this.rowTemplate = root.querySelector('[data-raid-row-template]');
        this.detailTemplate = root.querySelector('[data-raid-detail-template]');
        this.status = root.querySelector('[data-raid-status]');
        this.nationId = root.dataset.nationId;
        this.rows = new Map();
    }

    init() {
        this.restoreFilters();
        this.form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.loadTargets({ fresh: false });
        });
        this.root.querySelector('[data-raid-refresh]').addEventListener('click', (event) => {
            this.loadTargets({ fresh: true, button: event.currentTarget });
        });
        window.setInterval(() => this.refreshRelativeTimes(), 60_000);
        this.loadTargets({ fresh: false });
    }

    restoreFilters() {
        const stored = readStoredFilters();

        for (const field of FILTER_FIELDS) {
            const input = this.form.elements.namedItem(field);

            if (!input || stored[field] === undefined) {
                continue;
            }

            if (input.type === 'checkbox') {
                input.checked = Boolean(stored[field]);
            } else {
                input.value = stored[field];
            }
        }
    }

    filterValues() {
        const values = {};

        for (const field of FILTER_FIELDS) {
            const input = this.form.elements.namedItem(field);

            if (input) {
                values[field] = input.type === 'checkbox' ? input.checked : input.value.trim();
            }
        }

        return values;
    }

    finderUrl(fresh) {
        const values = this.filterValues();
        storeFilters(values);
        const nationInput = this.form.elements.namedItem('nation_id');
        this.nationId = nationInput?.value.trim() || this.nationId;
        const url = new URL(this.root.dataset.raidFinderEndpoint.replace('__NATION__', encodeURIComponent(this.nationId)), window.location.origin);

        for (const [key, value] of Object.entries(values)) {
            if (value === true) {
                url.searchParams.set(key, '1');
            } else if (value !== false && value !== '') {
                url.searchParams.set(key, value);
            }
        }

        if (fresh) {
            url.searchParams.set('fresh', '1');
        }

        return url;
    }

    async loadTargets({ fresh, button = null }) {
        this.showState('loading');
        setButtonBusy(button, true, 'Refreshing…');

        try {
            const { data } = await requestJson(this.finderUrl(fresh));
            this.render(data);
            const count = data.data.length;
            this.showState(count === 0 ? 'empty' : 'results');
            announce(`Loaded ${count} ${count === 1 ? 'target' : 'targets'}.`, this.status);
        } catch (error) {
            this.showError(error);
        } finally {
            setButtonBusy(button, false);
        }
    }

    showState(state) {
        this.root.querySelector('[data-raid-skeleton]').hidden = state !== 'loading';
        this.root.querySelector('[data-raid-results]').hidden = state !== 'results';
        this.root.querySelector('[data-raid-empty]').hidden = state !== 'empty';
        this.root.querySelector('[data-raid-error]').hidden = state !== 'error';
        this.root.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    }

    showError(error) {
        const message = error instanceof AsyncRequestError ? error.message : 'Raid targets could not be loaded.';
        const retry = error instanceof AsyncRequestError && error.retryAfter ? ` Try again in ${error.retryAfter} seconds.` : '';
        this.root.querySelector('[data-raid-error-message]').textContent = message + retry;
        this.root.querySelector('[data-raid-error-support]').textContent = error?.supportId ? `Support ID: ${error.supportId}` : '';
        this.showState('error');
        announce(message, this.status);
    }

    render({ data, meta }) {
        this.renderMeta(meta);
        this.rows.clear();
        this.rowsBody.replaceChildren(...data.flatMap((row) => this.renderRow(row)));
    }

    renderMeta(meta) {
        const attacker = meta.attacker;
        this.root.querySelector('[data-raid-attacker-summary]').textContent =
            `Score range ${amount.format(attacker.range_min)}–${amount.format(attacker.range_max)} · ` +
            `offensive slots ${attacker.offensive_wars}/${attacker.offensive_capacity}.`;
        this.root.querySelector('[data-raid-planning-warning]').hidden = !attacker.planning_only;
        setTime(this.root.querySelector('[data-raid-generated]'), meta.generated_at);
        this.root.querySelector('[data-raid-candidate-count]').textContent = amount.format(meta.candidate_count);
        this.root.querySelector('[data-raid-model-version]').textContent = meta.model_version;
    }

    renderRow(row) {
        const fragment = this.rowTemplate.content.cloneNode(true);
        const tr = fragment.querySelector('[data-raid-row]');
        const { nation, valuation } = row;
        const find = (selector) => tr.querySelector(selector);

        tr.dataset.targetId = nation.id;
        cell(find('[data-raid-rank]'), `#${row.rank}`);
        const link = cell(find('[data-raid-nation-link]'), nation.leader_name || nation.nation_name);
        link.href = `https://politicsandwar.com/nation/id=${nation.id}`;
        link.title = nation.nation_name;
        cell(find('[data-raid-alliance]'), nation.alliance?.name ?? 'No alliance');
        const position = find('[data-raid-position]');
        position.hidden = !nation.alliance;
        position.textContent = nation.alliance_position ? label(nation.alliance_position.toLowerCase()) : '';
        cell(find('[data-raid-cities]'), amount.format(nation.num_cities));
        setTime(find('[data-raid-last-active]'), nation.last_active);
        cell(find('[data-raid-activity]'), label(nation.activity_bucket));
        cell(find('[data-raid-military]'), `S ${compact.format(nation.soldiers)} · T ${compact.format(nation.tanks)} · A ${compact.format(nation.aircraft)} · Sh ${compact.format(nation.ships)}`);
        cell(find('[data-raid-slots]'), `${nation.defensive_wars}/3`);
        cell(find('[data-raid-expected-net]'), currency.format(valuation.expected_net));
        cell(find('[data-raid-expected-range]'), `${currency.format(valuation.expected_net_low)} – ${currency.format(valuation.expected_net_high)}`);
        const confidence = cell(find('[data-raid-confidence]'), label(valuation.confidence));
        confidence.classList.add(CONFIDENCE_BADGES[valuation.confidence] ?? 'badge-ghost');
        cell(find('[data-raid-odds]'), `${percent.format(valuation.win_probability)} / ${percent.format(valuation.victory_probability)}`);
        find('[data-raid-declare]').href = `https://politicsandwar.com/nation/war/declare/id=${nation.id}`;

        const detail = this.renderDetail(row);
        const entry = { row, tr, detail };
        this.rows.set(String(nation.id), entry);
        this.renderClaim(entry);

        const toggle = find('[data-raid-details]');
        toggle.addEventListener('click', () => this.toggleDetail(entry, toggle));
        find('[data-raid-check]').addEventListener('click', (event) => this.checkAvailability(entry, event.currentTarget));
        find('[data-raid-claim]').addEventListener('click', (event) => this.toggleClaim(entry, event.currentTarget));

        return [tr, detail];
    }

    renderDetail(row) {
        const detail = this.detailTemplate.content.cloneNode(true).querySelector('[data-raid-detail-row]');
        const { valuation } = row;
        const { stockpile } = valuation;
        detail.hidden = true;
        detail.id = `raid-detail-${row.nation.id}`;

        detail.querySelector('[data-raid-components]').replaceChildren(...Object.entries(COMPONENTS).map(([key, name]) => {
            const tr = document.createElement('tr');
            tr.append(cell(document.createElement('td'), name), cell(document.createElement('td'), currency.format(valuation.components[key] ?? 0)));

            return tr;
        }));

        const ageDays = (stockpile.evidence_age_hours / 24).toFixed(1);
        detail.querySelector('[data-raid-evidence]').textContent = stockpile.evidence_kind === 'loot'
            ? `Looted ${ageDays} days ago · retention ${percent.format(stockpile.retention)} · value ${currency.format(stockpile.value)} (${currency.format(stockpile.low_value)} – ${currency.format(stockpile.high_value)})`
            : `Production estimate only · retention ${percent.format(stockpile.retention)} · value ${currency.format(stockpile.value)}`;
        detail.querySelector('[data-raid-stockpile]').replaceChildren(...RESOURCES.map((resource) => {
            const tr = document.createElement('tr');
            const value = stockpile.resources[resource] ?? 0;
            tr.append(
                cell(document.createElement('td'), label(resource)),
                cell(document.createElement('td'), resource === 'money' ? currency.format(value) : amount.format(value)),
            );

            return tr;
        }));

        const others = valuation.competition.other_attackers;
        detail.querySelector('[data-raid-competition]').textContent = others === 0 ? 'No other attackers' : `${others} other ${others === 1 ? 'attacker' : 'attackers'}`;
        detail.querySelector('[data-raid-counter]').textContent = `Counter risk ${percent.format(valuation.counter.probability)}`;
        detail.querySelector('[data-raid-assumptions]').replaceChildren(
            ...valuation.assumptions.map((assumption) => cell(document.createElement('li'), assumption)),
        );

        return detail;
    }

    toggleDetail(entry, toggle, forceOpen = false) {
        const open = forceOpen || entry.detail.hidden;
        entry.detail.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-controls', entry.detail.id);
    }

    async checkAvailability(entry, button) {
        this.toggleDetail(entry, entry.tr.querySelector('[data-raid-details]'), true);
        const area = entry.detail.querySelector('[data-raid-availability]');
        const status = area.querySelector('[data-raid-availability-status]');
        const reasons = area.querySelector('[data-raid-availability-reasons]');
        const url = new URL(this.root.dataset.raidAvailabilityEndpoint, window.location.origin);
        url.searchParams.set('nation_id', this.nationId);
        url.searchParams.set('target_id', entry.row.nation.id);
        area.hidden = false;
        setButtonBusy(button, true, 'Checking…');

        try {
            const { data } = await requestJson(url);
            status.textContent = data.eligible ? 'Eligible now' : (data.planning_only ? 'Planning only' : 'Not available');
            reasons.replaceChildren(...data.reasons.map((reason) => cell(document.createElement('li'), reason)));
            announce(`${entry.row.nation.leader_name}: ${status.textContent}.`, this.status);
        } catch (error) {
            const retry = error?.status === 429 && error.retryAfter ? ` Try again in ${error.retryAfter} seconds.` : '';
            status.textContent = (error?.message ?? 'Availability could not be checked.') + retry;
            reasons.replaceChildren();
            announce(status.textContent, this.status);
        } finally {
            setButtonBusy(button, false);
        }
    }

    renderClaim(entry) {
        const { claim } = entry.row;
        const button = entry.tr.querySelector('[data-raid-claim]');
        const status = entry.tr.querySelector('[data-raid-claim-status]');

        if (!claim) {
            status.textContent = '';
            button.textContent = 'Claim';
            button.hidden = false;
        } else if (claim.mine) {
            status.textContent = `Claimed by you until ${new Date(claim.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
            button.textContent = 'Release';
            button.hidden = false;
        } else {
            status.textContent = `Claimed by ${claim.leader_name ?? 'another member'}`;
            button.hidden = true;
        }
    }

    async toggleClaim(entry, button) {
        const claim = entry.row.claim;
        const headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() };
        const endpoint = this.root.dataset.raidClaimsEndpoint;
        setButtonBusy(button, true, claim ? 'Releasing…' : 'Claiming…');

        try {
            if (claim) {
                await requestJson(`${endpoint}/${encodeURIComponent(claim.id)}`, { method: 'DELETE', headers });
                entry.row.claim = null;
                announce(`Released ${entry.row.nation.leader_name}.`, this.status);
            } else {
                const { data } = await requestJson(endpoint, {
                    method: 'POST',
                    headers,
                    body: JSON.stringify({ target_nation_id: entry.row.nation.id }),
                });
                entry.row.claim = data.data;
                announce(`Claimed ${entry.row.nation.leader_name}.`, this.status);
            }
        } catch (error) {
            entry.tr.querySelector('[data-raid-claim-status]').textContent = error?.message ?? 'The claim could not be updated.';
            announce(error?.message ?? 'The claim could not be updated.', this.status);
            setButtonBusy(button, false);

            return;
        }

        setButtonBusy(button, false);
        this.renderClaim(entry);
    }

    refreshRelativeTimes() {
        for (const element of this.root.querySelectorAll('[data-raid-relative]')) {
            element.textContent = relativeTime(element.dataset.timestamp);
        }
    }
}

export const initRaidFinders = (root = document) => {
    for (const element of root.querySelectorAll('[data-raid-finder]')) {
        if (element.dataset.raidFinderReady === 'true') {
            continue;
        }

        element.dataset.raidFinderReady = 'true';
        new RaidFinder(element).init();
    }
};
