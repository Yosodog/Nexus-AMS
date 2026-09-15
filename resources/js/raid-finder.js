import {
    announce,
    AsyncRequestError,
    getAppName,
    requestJson,
    setButtonBusy,
    startRetryCountdown,
} from './async-ui';

const FILTER_KEYS = [
    'q',
    'min_cities',
    'max_cities',
    'max_wars',
    'min_loot',
    'inactivity',
    'military',
    'min_return',
    'sort',
];
const FRESH_FOR_MS = 5 * 60 * 1000;
const REFRESH_POLL_MIN_MS = 2 * 1000;
const REFRESH_POLL_MAX_MS = 5 * 1000;
const AVAILABILITY_STALE_REASON = 'Availability needs a fresh check.';
const SORT_LABELS = {
    expected_net: 'expected net return',
    conservative: 'conservative return',
    gross: 'gross loot',
    efficiency: 'slot efficiency',
};
const RESOURCE_LABELS = {
    money: 'Cash',
    cash: 'Cash',
    food: 'Food',
    coal: 'Coal',
    oil: 'Oil',
    uranium: 'Uranium',
    iron: 'Iron',
    bauxite: 'Bauxite',
    lead: 'Lead',
    gasoline: 'Gasoline',
    munitions: 'Munitions',
    steel: 'Steel',
    aluminum: 'Aluminum',
};

const asNumber = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
};

const firstNumber = (...values) => {
    for (const value of values) {
        const number = asNumber(value);

        if (number !== null) {
            return number;
        }
    }

    return null;
};

const asObject = (value) => value && typeof value === 'object' ? value : {};

const formatMoney = (value) => {
    const number = asNumber(value);

    if (number === null) {
        return 'Unavailable';
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(number);
};

const formatSignedMoney = (value) => {
    const number = asNumber(value);

    if (number === null) {
        return 'Unavailable';
    }

    return `${number >= 0 ? '+' : ''}${formatMoney(number)}`;
};

const formatNumber = (value, digits = 0) => {
    const number = asNumber(value);

    if (number === null) {
        return 'Unavailable';
    }

    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: digits,
        minimumFractionDigits: digits,
    }).format(number);
};

const formatPercent = (value) => {
    const number = asNumber(value);

    if (number === null) {
        return 'Unavailable';
    }

    return `${formatNumber(number <= 1 ? number * 100 : number, 0)}%`;
};

const formatHours = (value) => {
    const number = asNumber(value);

    if (number === null) {
        return 'Unavailable';
    }

    if (number < 24) {
        return `${formatNumber(number, 1)}h`;
    }

    return `${formatNumber(number / 24, 1)}d`;
};

const formatDate = (value) => {
    if (!value) {
        return 'Unavailable';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString();
};

const labelFor = (value) => String(value ?? '')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

const numericFilter = (form, name) => {
    const rawValue = String(new FormData(form).get(name) ?? '').trim();

    if (rawValue === '') {
        return null;
    }

    return asNumber(rawValue);
};

const targetPrediction = (target) => asObject(target?.prediction);

const targetValuation = (target) => asObject(targetPrediction(target).valuation);

const targetIntelligence = (target) => asObject(target?.intelligence);

const targetAvailability = (target) => asObject(target?.availability);

const targetExpectedNet = (target) => {
    const prediction = targetPrediction(target);

    return firstNumber(
        prediction.expected_net,
        target.value,
    );
};

const targetGrossLoot = (target) => {
    const prediction = targetPrediction(target);

    return firstNumber(
        prediction.gross_loot,
        target.value,
    );
};

const targetConservativeNet = (target) => {
    const prediction = targetPrediction(target);

    return firstNumber(
        prediction.conservative_net,
        targetExpectedNet(target),
    );
};

const targetSlotEfficiency = (target) => {
    const prediction = targetPrediction(target);

    return firstNumber(prediction.slot_efficiency);
};

const targetDurationHours = (target) => firstNumber(targetPrediction(target).duration_hours);

const targetWinProbability = (target) => firstNumber(targetPrediction(target).win_probability);

const targetConfidence = (target) => {
    return targetPrediction(target).confidence;
};

const valuationBasis = (target) => String(targetValuation(target).basis ?? '').trim().toLowerCase();

const valuationBadgeLabel = (target) => {
    switch (valuationBasis(target)) {
        case 'lower_bound':
            return 'Lower bound';
        case 'complete':
            return 'Complete valuation';
        case 'unavailable':
            return 'Valuation unavailable';
        default:
            return '';
    }
};

const valuationRangeLabel = (target) => {
    const valuation = targetValuation(target);
    const lowerBound = asNumber(valuation.lower_bound);
    const upperBound = asNumber(valuation.upper_bound);

    if (valuationBasis(target) === 'lower_bound') {
        if (lowerBound !== null && upperBound !== null && upperBound >= lowerBound) {
            return `Lower-bound estimate ${formatMoney(lowerBound)} to ${formatMoney(upperBound)}`;
        }

        if (lowerBound !== null) {
            return `Lower-bound estimate ${formatMoney(lowerBound)}`;
        }

        return 'Lower-bound estimate';
    }

    if (valuationBasis(target) === 'unavailable') {
        return 'Valuation unavailable';
    }

    return '';
};

const unknownComponentEntries = (target) => {
    const unknown = targetValuation(target).unknown_components;

    if (Array.isArray(unknown)) {
        return unknown;
    }

    if (unknown && typeof unknown === 'object') {
        return Object.entries(unknown).map(([key, value]) => {
            if (value && typeof value === 'object') {
                const detail = value.reason ?? value.description ?? value.value ?? value.name;

                return detail === undefined ? labelFor(key) : `${labelFor(key)}: ${detail}`;
            }

            return `${labelFor(key)}: ${value}`;
        });
    }

    return unknown === undefined || unknown === null || unknown === '' ? [] : [unknown];
};

const renderValuation = (target, badge, range, unknownSection, unknownList) => {
    const badgeLabel = valuationBadgeLabel(target);

    if (badge instanceof HTMLElement) {
        badge.textContent = badgeLabel;
        badge.hidden = badgeLabel === '';
        badge.classList.toggle('badge-warning', valuationBasis(target) === 'lower_bound');
        badge.classList.toggle('badge-ghost', valuationBasis(target) !== 'lower_bound');
        badge.setAttribute('aria-label', badgeLabel);
    }

    if (range instanceof HTMLElement) {
        const rangeLabel = valuationRangeLabel(target);
        range.textContent = rangeLabel;
        range.hidden = rangeLabel === '';
    }

    const entries = unknownComponentEntries(target);
    if (unknownSection instanceof HTMLElement) {
        unknownSection.hidden = entries.length === 0;
    }
    if (unknownList instanceof HTMLElement) {
        renderList(unknownList, entries, (entry) => {
            if (entry && typeof entry === 'object') {
                const label = entry.label ?? entry.component ?? entry.name ?? entry.key;
                const detail = entry.reason ?? entry.description ?? entry.value;

                return label && detail !== undefined ? `${label}: ${detail}` : String(label ?? detail ?? 'Unavailable');
            }

            return String(entry);
        });
    }
};

const targetMilitary = (target) => {
    const prediction = targetPrediction(target);
    const military = prediction.military_suitability;

    if (military && typeof military === 'object') {
        return military;
    }

    return {};
};

const militaryRank = (target) => {
    const military = targetMilitary(target);
    const score = firstNumber(military.score);

    if (score !== null) {
        const normalized = score <= 1 ? score * 100 : score;

        if (normalized >= 80) {
            return 3;
        }

        if (normalized >= 60) {
            return 2;
        }

        if (normalized >= 40) {
            return 1;
        }

        return 0;
    }

    return null;
};

const militaryLabel = (target) => {
    const military = targetMilitary(target);
    const score = firstNumber(military.score);

    const label = score === null
        ? null
        : score >= 80 ? 'Strong advantage' : score >= 60 ? 'Suitable' : score >= 40 ? 'Challenging' : 'Poor fit';

    if (label && score !== null) {
        return `${label} (${formatPercent(score)})`;
    }

    if (score !== null) {
        return formatPercent(score);
    }

    return 'Unavailable';
};

const confidenceLabel = (target) => {
    const confidence = targetConfidence(target);

    if (typeof confidence === 'string' && confidence.trim() !== '') {
        return labelFor(confidence);
    }

    return 'Unavailable';
};

const inactivityDays = (target) => {
    const lastActive = target.nation?.last_active;

    if (!lastActive) {
        return null;
    }

    const timestamp = new Date(lastActive).getTime();

    return Number.isNaN(timestamp) ? null : Math.max(0, (Date.now() - timestamp) / 86_400_000);
};

const resourceLabel = (key) => RESOURCE_LABELS[String(key).toLocaleLowerCase()] ?? labelFor(key);

const normalizeEntries = (value) => {
    if (Array.isArray(value)) {
        return value.map((entry, index) => {
            if (entry && typeof entry === 'object') {
                return {
                    key: entry.resource ?? entry.name ?? entry.label ?? index,
                    value: entry.value ?? entry.amount ?? entry.quantity ?? entry.total,
                    detail: entry.quantity ?? entry.amount ?? null,
                };
            }

            return { key: index, value: entry, detail: null };
        });
    }

    if (value && typeof value === 'object') {
        return Object.entries(value).map(([key, entry]) => {
            if (entry && typeof entry === 'object') {
                return {
                    key,
                    value: entry.value ?? entry.amount ?? entry.quantity ?? entry.total,
                    detail: entry.quantity ?? entry.amount ?? null,
                };
            }

            return { key, value: entry, detail: null };
        });
    }

    return [];
};

const valueForEntry = (entry, resource = false) => {
    const value = asNumber(entry.value);

    if (value === null) {
        return String(entry.value ?? 'Unavailable');
    }

    if (resource) {
        return formatNumber(value, value % 1 === 0 ? 0 : 2);
    }

    return formatMoney(value);
};

const addTextItem = (container, text, className = '') => {
    const item = document.createElement('li');
    item.textContent = text;

    if (className !== '') {
        item.className = className;
    }

    container.appendChild(item);
};

const renderList = (container, value, formatter = (entry) => String(entry ?? '')) => {
    if (!(container instanceof HTMLElement)) {
        return;
    }

    container.replaceChildren();
    const entries = Array.isArray(value) ? value : value ? [value] : [];

    if (entries.length === 0) {
        addTextItem(container, 'Unavailable', 'list-none pl-0 text-base-content/60');
        return;
    }

    entries.forEach((entry) => addTextItem(container, formatter(entry)));
};

const renderComponents = (container, target) => {
    if (!(container instanceof HTMLElement)) {
        return;
    }

    container.replaceChildren();
    const prediction = targetPrediction(target);
    const components = prediction.components ?? target.components;
    const entries = normalizeEntries(components);
    const fallback = [
        ['Gross loot', targetGrossLoot(target)],
        ['Expected net return', targetExpectedNet(target)],
        ['Conservative return', targetConservativeNet(target)],
        ['Slot efficiency', targetSlotEfficiency(target) === null ? null : `${formatMoney(targetSlotEfficiency(target))}/slot-hour`],
    ];

    if (entries.length === 0) {
        fallback.forEach(([key, value]) => {
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-3 border-b border-base-300 pb-2 last:border-0';

            const label = document.createElement('dt');
            label.className = 'nexus-text-muted';
            label.textContent = key;
            const amount = document.createElement('dd');
            amount.className = 'font-medium tabular-nums';
            amount.textContent = typeof value === 'string' ? value : formatMoney(value);
            row.append(label, amount);
            container.appendChild(row);
        });
        return;
    }

    entries.forEach((entry) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between gap-3 border-b border-base-300 pb-2 last:border-0';
        const label = document.createElement('dt');
        label.className = 'nexus-text-muted';
        label.textContent = labelFor(entry.key);
        const amount = document.createElement('dd');
        amount.className = 'font-medium tabular-nums';
        amount.textContent = valueForEntry(entry);
        row.append(label, amount);
        container.appendChild(row);
    });
};

const renderResources = (container, target) => {
    if (!(container instanceof HTMLElement)) {
        return;
    }

    container.replaceChildren();
    const prediction = targetPrediction(target);
    const resources = prediction.loot_resources;
    const entries = normalizeEntries(resources);

    if (entries.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'col-span-2 text-base-content/60';
        empty.textContent = 'Unavailable';
        container.appendChild(empty);
        return;
    }

    entries.forEach((entry) => {
        const item = document.createElement('div');
        item.className = 'rounded-lg border border-base-300 bg-base-100/60 px-3 py-2';
        const title = document.createElement('div');
        title.className = 'text-xs uppercase nexus-text-muted';
        title.textContent = resourceLabel(entry.key);
        const amount = document.createElement('div');
        amount.className = 'mt-1 font-medium tabular-nums';
        amount.textContent = valueForEntry(entry, true);
        item.append(title, amount);
        container.appendChild(item);
    });
};

const renderCostResources = (target) => {
    const prediction = targetPrediction(target);
    const costs = prediction.cost_resources;
    const entries = normalizeEntries(costs);

    return entries.length === 0
        ? ''
        : `Costs: ${entries.map((entry) => `${resourceLabel(entry.key)} ${valueForEntry(entry, true)}`).join(', ')}`;
};

const approachLabel = (approach) => {
    if (approach && typeof approach === 'object') {
        return approach.label ?? 'Approach';
    }

    return approach ?? 'Unavailable';
};

const approachDescription = (approach) => {
    if (!approach || typeof approach !== 'object') {
        return '';
    }

    return approach.reason ?? '';
};

const renderApproaches = (container, target) => {
    if (!(container instanceof HTMLElement)) {
        return;
    }

    container.replaceChildren();
    const prediction = targetPrediction(target);
    const approaches = (Array.isArray(prediction.approaches) ? prediction.approaches : [])
        .filter((entry) => approachLabel(entry) !== approachLabel(prediction.approach));

    if (approaches.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'text-sm nexus-text-muted';
        empty.textContent = 'No alternate approaches were returned.';
        container.appendChild(empty);
        return;
    }

    approaches.forEach((approach) => {
        const card = document.createElement('div');
        card.className = 'py-3 text-sm';
        const title = document.createElement('div');
        title.className = 'font-semibold';
        title.textContent = String(approachLabel(approach));
        card.appendChild(title);
        const description = approachDescription(approach);

        if (description) {
            const summary = document.createElement('p');
            summary.className = 'mt-1 nexus-text-muted';
            summary.textContent = description;
            card.appendChild(summary);
        }

        const metrics = [];
        const expected = firstNumber(approach?.expected_net);
        const duration = firstNumber(approach?.duration_hours);
        const win = firstNumber(approach?.win_probability);

        if (expected !== null) metrics.push(`Net ${formatMoney(expected)}`);
        if (duration !== null) metrics.push(`Duration ${formatHours(duration)}`);
        if (win !== null && approach.available !== false) metrics.push(`Win ${formatPercent(win)}`);
        if (approach?.attacks !== undefined) metrics.push(`${formatNumber(approach.attacks)} attacks`);
        if (metrics.length > 0) {
            const metricLine = document.createElement('p');
            metricLine.className = 'mt-2 text-xs nexus-text-muted';
            metricLine.textContent = metrics.join(' · ');
            card.appendChild(metricLine);
        }

        container.appendChild(card);
    });
};

const availabilityStatus = (target) => {
    const availability = targetAvailability(target);
    const eligible = availability.eligible;

    if (eligible === true) {
        return 'Eligible now';
    }

    if (availability.planning_only === true) {
        return `Planning only · ${availability.offensive_wars}/${availability.offensive_capacity} offensive slots occupied`;
    }

    if (eligible === false) {
        return 'Unavailable';
    }

    return 'Not checked';
};

const availabilityReasons = (target) => {
    const reasons = targetAvailability(target).reasons;

    if (!Array.isArray(reasons)) {
        return reasons ? [reasons] : [];
    }

    return reasons;
};

const setAvailabilityTone = (element, target) => {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    element.classList.remove('text-success', 'text-error', 'text-warning');
    const status = availabilityStatus(target);

    if (status === 'Eligible now') {
        element.classList.add('text-success');
    } else if (status === 'Unavailable') {
        element.classList.add('text-error');
    } else {
        element.classList.add('text-warning');
    }
};

const renderMilitaryComparison = (container, target) => {
    if (!(container instanceof HTMLElement)) {
        return;
    }

    container.replaceChildren();
    const military = targetMilitary(target);
    const attacker = military.attacker;
    const defender = military.target;
    const units = ['soldiers', 'tanks', 'aircraft', 'ships'];

    if (!attacker || !defender || typeof attacker !== 'object' || typeof defender !== 'object') {
        const empty = document.createElement('p');
        empty.className = 'col-span-3 text-sm nexus-text-muted';
        empty.textContent = 'Unavailable';
        container.appendChild(empty);
        return;
    }

    const headings = [
        ['Unit', ''],
        ['Your force', 'text-right'],
        ['Target force', 'text-right'],
    ];
    headings.forEach(([label, className]) => {
        const heading = document.createElement('dt');
        heading.className = `font-semibold ${className}`.trim();
        heading.textContent = label;
        container.appendChild(heading);
    });

    units.forEach((unit) => {
        const label = document.createElement('dt');
        label.className = 'nexus-text-muted';
        label.textContent = labelFor(unit);
        const attackerValue = document.createElement('dd');
        attackerValue.className = 'text-right tabular-nums';
        attackerValue.textContent = formatNumber(attacker[unit]);
        const defenderValue = document.createElement('dd');
        defenderValue.className = 'text-right tabular-nums';
        defenderValue.textContent = formatNumber(defender[unit]);
        container.append(label, attackerValue, defenderValue);
    });
};

const renderAvailability = (row, detailRow, target) => {
    const status = detailRow?.querySelector('[data-raid-availability-status]');
    const reasons = detailRow?.querySelector('[data-raid-availability-reasons]');
    const checked = detailRow?.querySelector('[data-raid-availability-checked]');

    if (status instanceof HTMLElement) {
        status.textContent = availabilityStatus(target);
        setAvailabilityTone(status, target);
    }

    if (reasons instanceof HTMLElement) {
        const items = availabilityReasons(target);
        reasons.textContent = items.length > 0 ? items.map((reason) => String(reason?.message ?? reason)).join(' · ') : '';
        reasons.hidden = items.length === 0;
    }

    if (checked instanceof HTMLElement) {
        const checkedAt = targetAvailability(target).checked_at;
        checked.textContent = checkedAt ? `Checked ${formatDate(checkedAt)}` : '';
        checked.hidden = !checkedAt;
    }

    const returnStatus = row?.querySelector('[data-raid-return-status]');
    const availability = targetAvailability(target);

    if (returnStatus instanceof HTMLElement && availability.eligible === false) {
        returnStatus.textContent = availability.planning_only === true
            ? availabilityStatus(target)
            : 'Unavailable now';
        returnStatus.classList.add(availability.planning_only === true ? 'text-warning' : 'text-error');
    }
};

const workNote = (container, text) => {
    const note = document.createElement('p');
    note.className = 'nexus-text-muted max-w-prose whitespace-normal';
    note.textContent = text;
    container.append(note);
};

const workTable = (container, caption, headers, rows) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'max-w-full overflow-x-auto';
    wrapper.tabIndex = 0;
    wrapper.setAttribute('role', 'region');
    wrapper.setAttribute('aria-label', caption);
    const table = document.createElement('table');
    table.className = 'table table-sm w-full tabular-nums';
    const title = document.createElement('caption');
    title.className = 'sr-only';
    title.textContent = caption;
    const head = document.createElement('thead');
    const heading = document.createElement('tr');
    headers.forEach((label) => {
        const cell = document.createElement('th');
        cell.scope = 'col';
        cell.className = 'whitespace-normal';
        cell.textContent = label;
        heading.append(cell);
    });
    head.append(heading);
    const body = document.createElement('tbody');
    rows.forEach((values) => {
        const row = document.createElement('tr');
        values.forEach((value, index) => {
            const cell = document.createElement(index === 0 ? 'th' : 'td');
            if (index === 0) cell.scope = 'row';
            cell.className = 'whitespace-normal align-top';
            if (value instanceof Node) cell.append(value);
            else cell.textContent = String(value ?? 'Unavailable');
            row.append(cell);
        });
        body.append(row);
    });
    table.append(title, head, body);
    wrapper.append(table);
    container.append(wrapper);
};

const evidenceSourceLabel = (source) => ({
    loot_report: 'Reported percentage',
    historical_inputs: 'Calculated from historical inputs',
    formula_defaults: 'Estimated with default inputs',
    historical_snapshot: 'Dated nation observation',
    attack_observation: 'Historical attack record',
    default: 'Assumed; historical value missing',
}[source] ?? labelFor(source ?? 'unavailable'));

const renderCalculationWork = (detailRow, target) => {
    const calculation = asObject(target.calculation);
    const evidence = detailRow.querySelector('[data-raid-evidence]');
    const stockpile = detailRow.querySelector('[data-raid-stockpile-work]');
    evidence.replaceChildren();
    stockpile.replaceChildren();
    detailRow.querySelector('[data-raid-calculation-stamp]').textContent = calculation.as_of
        ? `Calculated ${formatDate(calculation.as_of)}${calculation.model_version ? ` · Model ${calculation.model_version}` : ''}`
        : 'Calculation evidence not recorded';
    const uncertainties = (Array.isArray(calculation.uncertainties) ? calculation.uncertainties : [])
        .filter((entry) => typeof entry === 'string' && /unknown|missing|unavailable|unverified|incomplete|uncertain|range|estimated|competition|transfer|spending/i.test(entry));
    detailRow.querySelector('[data-raid-main-uncertainty]').textContent = uncertainties[0]
        ?? 'Reported loot anchors the estimate; unobserved transfers and spending can change the remaining balance.';
    const observations = Array.isArray(calculation.observations) ? calculation.observations : [];
    const dates = observations.map((entry) => entry.observed_at).filter(Boolean).sort();
    detailRow.querySelector('[data-raid-evidence-summary]').textContent = dates.length > 0
        ? `Latest evidence ${formatDate(dates.at(-1))}` : 'No usable loot observation recorded';
    if (observations.length > 0) {
        workTable(evidence, 'Reported loot evidence', ['Resource', 'Observed', 'War', 'Looted', 'Fraction used', 'Basis'], observations.map((entry) => {
            let war = 'Unavailable';
            const id = asNumber(entry.war_id);
            if (id !== null && Number.isInteger(id) && id > 0) {
                war = document.createElement('a');
                war.href = `https://politicsandwar.com/nation/war/timeline/war=${id}`;
                war.target = '_blank';
                war.rel = 'noopener noreferrer';
                war.className = 'link link-primary';
                war.textContent = `War ${id}`;
            }
            return [resourceLabel(entry.resource), formatDate(entry.observed_at), war, formatNumber(entry.looted), formatPercent(entry.fraction), evidenceSourceLabel(entry.fraction_source)];
        }));
    } else {
        workNote(evidence, 'No usable historical loot observation is attached to this prediction. Any production-based balance remains an estimate.');
    }
    const seenInputs = new Set();
    observations.forEach((entry) => {
        const inputs = Object.entries(asObject(entry.fraction_inputs));
        const identity = JSON.stringify([entry.war_id, entry.observed_at, entry.fraction_inputs]);
        if (inputs.length === 0 || seenInputs.has(identity)) return;
        seenInputs.add(identity);
        const group = document.createElement('details');
        group.className = 'border-t border-base-300 pt-3';
        group.dataset.raidDisclosureKey = `modifiers-${entry.war_id}-${entry.observed_at}`;
        const summary = document.createElement('summary');
        summary.className = 'cursor-pointer font-medium focus-visible:outline-2 focus-visible:outline-primary';
        summary.textContent = `Modifier inputs${entry.war_id ? ` · War ${entry.war_id}` : ''}`;
        group.append(summary);
        workTable(group, 'Loot modifier inputs', ['Input', 'Value used', 'Evidence'], inputs.map(([key, input]) => [
            labelFor(key), typeof input.value === 'boolean' ? (input.value ? 'Yes' : 'No') : labelFor(input.value ?? 'unavailable'), evidenceSourceLabel(input.source),
        ]));
        evidence.append(group);
    });
    workNote(evidence, asObject(calculation.coverage).label ?? 'Evidence coverage unavailable.');
    workNote(evidence, asObject(asObject(calculation.coverage).history).label ?? 'History coverage has not been verified; absence of a report does not establish that no looting occurred.');
    const resources = Object.entries(asObject(calculation.resources));
    if (resources.length === 0) {
        workNote(stockpile, 'The resource calculation was not recorded for this result. A newly calculated result can include the evidence; refreshing availability alone will not create it.');
        return;
    }
    workNote(stockpile, 'Remaining after defeat = reported loot ÷ historical loot fraction − reported loot. Then apply modeled net production and subsequent observed depletion.');
    const hasSeparateChanges = resources.some(([, values]) => asNumber(values.production) !== null || asNumber(values.depletion) !== null);
    const steps = [
        ['Reconstructed before defeat', 'before_loot'],
        ['Reported victory loot', 'reported_loot'],
        ['Remaining after defeat', 'post_loot'],
        ...(resources.some(([, values]) => values.source && values.source !== 'victory') ? [['Starting balance used by model', 'baseline_balance']] : []),
        ...(hasSeparateChanges ? [
            ['Net production since baseline', 'production'],
            ['Subsequent observed depletion', 'depletion'],
        ] : [['Combined modeled change since baseline', 'net_change']]),
        ['Estimated current stockpile', 'current'],
    ];
    const totals = steps.map(([label, key]) => {
        let total = 0;
        let known = 0;
        resources.forEach(([resource, values]) => {
            const amount = asNumber(values[key]);
            const price = firstNumber(values.unit_price, asObject(asObject(calculation.prices).liquidation)[resource]);
            if (amount !== null && price !== null) {
                total += amount * price;
                known++;
            }
        });
        return [label, known > 0 ? formatMoney(total) : 'Unavailable', known === resources.length ? 'All listed resources valued' : `${known} of ${resources.length} resources valued`];
    });
    workTable(stockpile, 'Stockpile calculation in dollars', ['Calculation', 'Value', 'Coverage'], totals);
    if (!hasSeparateChanges) workNote(stockpile, 'Combined change includes modeled production, consumption, and observed depletion. Their individual contributions were not recorded separately.');
    workNote(stockpile, calculation.prices_at ? `Valued using the prediction’s prices from ${formatDate(calculation.prices_at)}.` : 'Price snapshot time unavailable.');
    const quantities = document.createElement('details');
    quantities.className = 'border-t border-base-300 pt-3';
    const summary = document.createElement('summary');
    summary.className = 'cursor-pointer font-medium focus-visible:outline-2 focus-visible:outline-primary';
    summary.textContent = 'Show resource quantities';
    quantities.append(summary);
    workTable(quantities, 'Stockpile resource arithmetic', [
        'Resource', 'Starting balance',
        ...(hasSeparateChanges ? ['Net production', 'Later depletion'] : ['Combined change']),
        'Current estimate', 'Scenario range',
    ], resources.map(([resource, values]) => [
        resourceLabel(resource), formatNumber(values.baseline_balance ?? values.post_loot),
        ...(hasSeparateChanges ? [formatNumber(values.production), formatNumber(values.depletion)] : [formatNumber(values.net_change)]),
        formatNumber(values.current),
        asNumber(values.lower) === null && asNumber(values.upper) === null ? 'Unavailable' : `${formatNumber(values.lower)} – ${formatNumber(values.upper)}`,
    ]));
    stockpile.append(quantities);
    workNote(stockpile, 'Unknown transfers, taxes, and spending are not shown as zero. Scenario ranges are modeled assumptions, not guaranteed bounds.');
    if (uncertainties.length > 1) {
        const list = document.createElement('ul');
        list.className = 'list-disc space-y-1 pl-5 whitespace-normal';
        uncertainties.slice(1, 3).forEach((text) => {
            const item = document.createElement('li');
            item.textContent = text;
            list.append(item);
        });
        stockpile.append(list);
    }
};

const renderOutcomeWork = (container, target) => {
    container.replaceChildren();
    const prediction = targetPrediction(target);
    workTable(container, 'Expected raid proceeds and return', ['Calculation', 'Estimate'], [
        ['Gross proceeds', formatMoney(targetGrossLoot(target))],
        ['Net return after modeled costs', formatMoney(targetExpectedNet(target))],
        ['Conservative scenario return', formatMoney(targetConservativeNet(target))],
    ]);
    workNote(container, 'Net return = ground theft + nation victory loot + bank loot + eligible bounty − consumables − military replacement − infrastructure replacement. Unknown components remain excluded or unavailable as indicated in the return breakdown.');
    workNote(container, 'Ground theft reduces the cash balance before victory loot is calculated. Values are weighted across the modeled scenarios, rather than a guaranteed payout.');
    const actions = asObject(prediction.approach).actions;
    if (Array.isArray(actions) && actions.length > 0) {
        const groups = [];
        actions.forEach((action) => {
            const type = labelFor(action.type ?? 'Attack');
            if (groups.at(-1)?.type === type) groups.at(-1).count++;
            else groups.push({ type, count: 1 });
        });
        workTable(container, 'Suggested attack order', ['Order', 'Planned actions'], groups.map((group, index) => [
            index + 1, `${group.count} × ${group.type}`,
        ]));
        workNote(container, 'This is the planned action order; execution stops at victory or when further attacks cannot be made. Individual attack times and resistance checkpoints are not recorded in this prediction.');
    } else {
        workNote(container, 'An individual attack sequence is unavailable for this prediction.');
    }
    const costs = normalizeEntries(prediction.cost_resources);
    if (costs.length > 0) {
        workTable(container, 'Expected resource costs', ['Resource', 'Expected cost quantity'], costs.map((entry) => [resourceLabel(entry.key), valueForEntry(entry, true)]));
    }
};

const renderDetail = (detailRow, target) => {
    const nation = asObject(target.nation);
    const prediction = targetPrediction(target);
    const approach = prediction.approach;
    const title = nation.leader_name || nation.nation_name || `Nation ${nation.id ?? ''}`;
    const detailTitle = detailRow.querySelector('[data-raid-detail-title]');
    const intelligence = detailRow.querySelector('[data-raid-detail-intelligence]');
    const approachName = detailRow.querySelector('[data-raid-approach-label]');
    const approachSummary = detailRow.querySelector('[data-raid-approach-description]');
    const military = detailRow.querySelector('[data-raid-military-suitability]');
    const confidence = detailRow.querySelector('[data-raid-confidence]');
    const winProbability = detailRow.querySelector('[data-raid-win-probability]');
    const duration = detailRow.querySelector('[data-raid-duration]');

    if (detailTitle) detailTitle.textContent = title;
    if (intelligence) {
        const intel = targetIntelligence(target);
        const observed = intel.observed_at;
        const status = intel.status ?? (intel.stale === true ? 'stale' : 'current');
        intelligence.textContent = `${formatDate(observed)} · ${labelFor(status)}`;
    }
    if (military) military.textContent = militaryLabel(target);
    if (confidence) confidence.textContent = confidenceLabel(target);
    if (winProbability) winProbability.textContent = formatPercent(targetWinProbability(target));
    if (duration) duration.textContent = formatHours(targetDurationHours(target));
    if (approachName) approachName.textContent = String(approachLabel(approach));
    if (approachSummary) approachSummary.textContent = approachDescription(approach);
    const approachMetrics = detailRow.querySelector('[data-raid-approach-metrics]');
    const selected = (prediction.approaches ?? []).find((entry) => approachLabel(entry) === approachLabel(approach)) ?? asObject(approach);
    if (approachMetrics) {
        const attacks = firstNumber(selected.attacks, selected.mechanics?.actions_executed);
        approachMetrics.textContent = [
            attacks === null ? null : `${formatNumber(attacks)} ${attacks === 1 ? 'attack' : 'attacks'}`,
            formatHours(targetDurationHours(target)),
            `Net ${formatMoney(targetExpectedNet(target))}`,
        ].filter(Boolean).join(' · ');
    }

    renderCalculationWork(detailRow, target);
    renderOutcomeWork(detailRow.querySelector('[data-raid-outcome-work]'), target);
    renderComponents(detailRow.querySelector('[data-raid-components]'), target);
    renderResources(detailRow.querySelector('[data-raid-resources]'), target);
    renderApproaches(detailRow.querySelector('[data-raid-approaches]'), target);
    renderMilitaryComparison(detailRow.querySelector('[data-raid-military-comparison]'), target);
    renderAvailability(null, detailRow, target);
    renderValuation(
        target,
        detailRow.querySelector('[data-raid-detail-valuation-badge]'),
        detailRow.querySelector('[data-raid-valuation-range]'),
        detailRow.querySelector('[data-raid-unknown-components-section]'),
        detailRow.querySelector('[data-raid-unknown-components]'),
    );

    const assumptions = prediction.assumptions;
    renderList(detailRow.querySelector('[data-raid-assumptions]'), assumptions, (entry) => {
        if (entry && typeof entry === 'object') {
            const key = entry.label ?? entry.name ?? entry.key;
            const value = entry.value ?? entry.description ?? entry.assumption;

            return key ? `${labelFor(key)}: ${value ?? 'Unavailable'}` : String(value ?? 'Unavailable');
        }

        return String(entry);
    });

    const scenarios = prediction.scenarios;
    renderList(detailRow.querySelector('[data-raid-scenarios]'), scenarios, (entry) => {
        if (entry && typeof entry === 'object') {
            const name = entry.label ?? 'Scenario';
            const probability = firstNumber(entry.weight);
            const net = firstNumber(entry.expected_net);
            const metrics = [];
            if (probability !== null) metrics.push(formatPercent(probability));
            if (net !== null) metrics.push(formatSignedMoney(net));

            return `${name}${metrics.length > 0 ? ` (${metrics.join(' · ')})` : ''}`;
        }

        return String(entry);
    });

    const costs = renderCostResources(target);
    const costNote = detailRow.querySelector('[data-raid-cost-note]');

    if (costNote instanceof HTMLElement) {
        costNote.textContent = costs;
        costNote.hidden = costs === '';
    }
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
    const sortSummary = root.querySelector('[data-raid-sort-summary]');
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
    let refreshing = false;
    let refreshStartedAt = 0;
    let refreshBaseline = null;
    let updatedAt = null;
    let freshnessTimeout = null;
    let refreshPollTimeout = null;
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

    const cancelRefreshPoll = () => {
        if (refreshPollTimeout !== null) {
            window.clearTimeout(refreshPollTimeout);
            refreshPollTimeout = null;
        }
    };

    const scheduleRefreshPoll = (retryAfter = null) => {
        cancelRefreshPoll();

        if (document.visibilityState !== 'visible') {
            return;
        }

        const seconds = asNumber(retryAfter) ?? REFRESH_POLL_MIN_MS / 1000;
        const delay = Math.min(
            REFRESH_POLL_MAX_MS,
            Math.max(REFRESH_POLL_MIN_MS, seconds * 1000),
        );
        refreshPollTimeout = window.setTimeout(() => {
            refreshPollTimeout = null;

            if (document.visibilityState === 'visible') {
                loadTargets({ polling: true });
            }
        }, delay);
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
                const value = params.get(key);

                if (value !== null) {
                    control.value = value;
                }
            }
        });
    };

    const sortKey = () => {
        const value = String(new FormData(form).get('sort') ?? '').trim();

        return Object.hasOwn(SORT_LABELS, value) ? value : 'expected_net';
    };

    const filteredTargets = () => {
        const formData = new FormData(form);
        const search = String(formData.get('q') ?? '').trim().toLocaleLowerCase();
        const minimumCities = numericFilter(form, 'min_cities');
        const maximumCities = numericFilter(form, 'max_cities');
        const maximumWars = numericFilter(form, 'max_wars');
        const minimumLoot = numericFilter(form, 'min_loot');
        const minimumInactivity = numericFilter(form, 'inactivity');
        const minimumReturn = numericFilter(form, 'min_return');
        const militaryFilter = String(formData.get('military') ?? '').trim();
        const militaryMinimum = militaryFilter === 'strong' ? 3 : militaryFilter === 'suitable' ? 2 : null;

        return targets
            .filter((target) => {
                const leader = String(target.nation?.leader_name ?? '').toLocaleLowerCase();
                const alliance = String(target.nation?.alliance?.name ?? '').toLocaleLowerCase();
                const cities = firstNumber(target.nation?.num_cities) ?? 0;
                const wars = firstNumber(target.defensive_wars) ?? 0;
                const historicalLoot = firstNumber(target.value, target.last_beige, targetPrediction(target).historical_loot);
                const expectedReturn = targetExpectedNet(target);
                const inactivity = inactivityDays(target);
                const rank = militaryRank(target);

                return (search === '' || leader.includes(search) || alliance.includes(search))
                    && (minimumCities === null || cities >= minimumCities)
                    && (maximumCities === null || cities <= maximumCities)
                    && (maximumWars === null || wars <= maximumWars)
                    && (minimumLoot === null || (historicalLoot !== null && historicalLoot >= minimumLoot))
                    && (minimumReturn === null || (expectedReturn !== null && expectedReturn >= minimumReturn))
                    && (minimumInactivity === null || (inactivity !== null && inactivity >= minimumInactivity))
                    && (militaryMinimum === null || (rank !== null && rank >= militaryMinimum));
            })
            .sort((left, right) => {
                const metric = (target) => {
                    switch (sortKey()) {
                        case 'conservative':
                            return targetConservativeNet(target);
                        case 'gross':
                            return targetGrossLoot(target);
                        case 'efficiency':
                            return targetSlotEfficiency(target);
                        default:
                            return targetExpectedNet(target);
                    }
                };
                const leftMetric = metric(left) ?? Number.NEGATIVE_INFINITY;
                const rightMetric = metric(right) ?? Number.NEGATIVE_INFINITY;

                if (rightMetric !== leftMetric) {
                    return rightMetric - leftMetric;
                }

                return (targetGrossLoot(right) ?? 0) - (targetGrossLoot(left) ?? 0);
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
                if (targets.length > 0 && !requestInFlight && !refreshing) {
                    markAgedAvailability();
                    showState('stale', 'This page has been open for more than 5 minutes. Refresh before acting on a target.', {
                        keepResults: true,
                    });
                }
            }, remainingFreshTime);
        }
    };

    const renderRows = (visibleTargets) => {
        const expandedTargets = new Map();
        resultsBody.querySelectorAll('[data-raid-detail-row]:not([hidden])').forEach((detail) => {
            expandedTargets.set(detail.id, new Map(Array.from(detail.querySelectorAll('details')).map((section) => [
                section.dataset.raidDisclosureKey ?? section.querySelector('summary')?.textContent, section.open,
            ])));
        });
        resultsBody.replaceChildren();

        visibleTargets.forEach((target, index) => {
            const fragment = rowTemplate.content.cloneNode(true);
            const row = fragment.querySelector('[data-raid-row]');
            const detailRow = fragment.querySelector('[data-raid-detail-row]');
            const nation = asObject(target.nation);
            const nationId = firstNumber(nation.id, target.id);
            const prediction = targetPrediction(target);
            const nationLink = fragment.querySelector('[data-raid-nation-link]');
            const alliance = fragment.querySelector('[data-raid-alliance]');
            const cities = fragment.querySelector('[data-raid-cities]');
            const lastActive = fragment.querySelector('[data-raid-last-active]');
            const score = fragment.querySelector('[data-raid-score]');
            const wars = fragment.querySelector('[data-raid-wars]');
            const expectedNet = fragment.querySelector('[data-raid-expected-net]');
            const grossLoot = fragment.querySelector('[data-raid-gross-loot]');
            const valuationBadge = fragment.querySelector('[data-raid-valuation-badge]');
            const returnStatus = fragment.querySelector('[data-raid-return-status]');
            const lastBeige = fragment.querySelector('[data-raid-last-beige]');
            const intelligence = fragment.querySelector('[data-raid-intelligence]');
            const inspectButton = fragment.querySelector('[data-raid-inspect]');
            const recheckButton = fragment.querySelector('[data-raid-recheck]');

            if (!(row instanceof HTMLTableRowElement) || !(detailRow instanceof HTMLTableRowElement)) {
                return;
            }

            const detailId = `raid-detail-${nationId ?? index}`;
            detailRow.id = detailId;
            inspectButton?.setAttribute('aria-controls', detailId);
            recheckButton?.setAttribute('data-target-id', String(nationId ?? ''));

            if (nationLink instanceof HTMLAnchorElement) {
                nationLink.href = `https://politicsandwar.com/nation/id=${encodeURIComponent(nationId ?? '')}`;
                nationLink.textContent = nation.leader_name || `Nation ${nationId ?? ''}`;
            }

            if (alliance) alliance.textContent = nation.alliance?.name ?? 'None';
            if (cities) cities.textContent = formatNumber(nation.num_cities);
            if (score) score.textContent = formatNumber(nation.score, 2);
            if (wars) wars.textContent = formatNumber(target.defensive_wars);
            if (expectedNet) expectedNet.textContent = formatMoney(targetExpectedNet(target));
            if (grossLoot) grossLoot.textContent = `Gross ${formatMoney(targetGrossLoot(target))}`;
            renderValuation(target, valuationBadge);
            if (returnStatus) {
                const conservative = targetConservativeNet(target);
                const efficiency = targetSlotEfficiency(target);
                returnStatus.textContent = conservative === null
                    ? ''
                    : `Conservative ${formatMoney(conservative)}${efficiency === null ? '' : ` · ${formatMoney(efficiency)}/slot-hour`}`;
            }
            if (lastBeige) lastBeige.textContent = target.last_beige === null || target.last_beige === undefined ? 'Not available' : formatMoney(target.last_beige);

            if (intelligence) {
                const intel = targetIntelligence(target);
                const observed = intel.observed_at;
                intelligence.textContent = observed ? `Intel ${formatDate(observed)}${intel.stale === true ? ' · stale' : ''}` : 'Intel unavailable';
            }

            if (lastActive instanceof HTMLTimeElement) {
                const parsed = new Date(nation.last_active);
                lastActive.textContent = Number.isNaN(parsed.getTime()) ? 'Unknown' : parsed.toLocaleString();

                if (!Number.isNaN(parsed.getTime())) {
                    lastActive.dateTime = parsed.toISOString();
                }
            }

            renderDetail(detailRow, target);
            renderAvailability(row, detailRow, target);
            if (expandedTargets.has(detailId)) {
                detailRow.hidden = false;
                inspectButton?.setAttribute('aria-expanded', 'true');
                if (inspectButton) inspectButton.textContent = 'Hide details';
                const sections = expandedTargets.get(detailId);
                detailRow.querySelectorAll('details').forEach((section) => {
                    section.open = sections.get(section.dataset.raidDisclosureKey ?? section.querySelector('summary')?.textContent) ?? false;
                });
            }

            inspectButton?.addEventListener('click', () => {
                const isOpen = !detailRow.hasAttribute('hidden');
                detailRow.toggleAttribute('hidden', isOpen);
                inspectButton.setAttribute('aria-expanded', String(!isOpen));
                inspectButton.textContent = isOpen ? 'Inspect' : 'Hide details';

                if (!isOpen) {
                    detailRow.querySelector('[data-raid-recheck]')?.focus({ preventScroll: true });
                }
            });

            recheckButton?.addEventListener('click', () => checkAvailability(target, row, detailRow, recheckButton));
            resultsBody.appendChild(fragment);
        });

        resultCount.textContent = `${visibleTargets.length} ${visibleTargets.length === 1 ? 'result' : 'results'}`;
        if (sortSummary) sortSummary.textContent = `Sorted by ${SORT_LABELS[sortKey()]}`;
        window.initAppUi?.(results);
    };

    const markAgedAvailability = () => {
        const now = Date.now();
        let changed = false;

        targets = targets.map((target) => {
            const availability = targetAvailability(target);

            if (availability.eligible !== true && availability.eligible !== false) {
                return target;
            }

            const checkedAt = Date.parse(String(availability.checked_at ?? ''));
            if (!Number.isNaN(checkedAt) && now - checkedAt < FRESH_FOR_MS) {
                return target;
            }

            const reasons = availabilityReasons(target);
            if (!reasons.some((reason) => String(reason?.message ?? reason) === AVAILABILITY_STALE_REASON)) {
                reasons.push(AVAILABILITY_STALE_REASON);
            }
            changed = true;

            return {
                ...target,
                availability: {
                    ...availability,
                    eligible: null,
                    reasons,
                },
            };
        });

        if (changed) {
            renderRows(filteredTargets());
        }
    };

    const applyFilters = (options = {}) => {
        syncFiltersToUrl();

        if (requestInFlight && targets.length === 0 && !options.requestCompleted) {
            return;
        }

        const visibleTargets = filteredTargets();

        renderRows(visibleTargets);

        if (refreshing) {
            showState('loading', 'Raid returns are still being calculated. This page will update automatically.', {
                keepResults: targets.length > 0,
            });
            return;
        }

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
                : options.refreshState === 'temporary_failure'
                    ? 'The latest refresh failed, so these are the most recent saved targets.'
                    : 'Showing saved targets while newer intelligence becomes available.';
            showState('stale', reason, { keepResults: true });
            return;
        }

        showState('success', `Showing ${visibleTargets.length} raid ${visibleTargets.length === 1 ? 'target' : 'targets'}.`, {
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

    const loadTargets = async ({ polling = false } = {}) => {
        if (requestInFlight) {
            if (!polling) {
                announce('Raid targets are already being refreshed.');
            }

            return;
        }

        if (!polling) {
            cancelRefreshPoll();
            refreshStartedAt = Date.now();
            refreshBaseline = null;
        } else if (Date.now() - refreshStartedAt >= 180_000) {
            refreshing = false;
            cancelRefreshPoll();
            showState('temporary_failure', 'The calculation is taking longer than expected. Automatic checks have stopped. Try refreshing again shortly.', { keepResults: targets.length > 0 });
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
            const endpoint = `${String(root.dataset.raidFinderEndpoint ?? '').replace(/\/$/, '')}/${encodeURIComponent(nationId)}`;
            const pollQuery = polling ? `?${new URLSearchParams({ poll: '1', after: refreshBaseline ?? '' })}` : '';
            const { data, meta } = await requestJson(`${endpoint}${pollQuery}`);
            if (!polling) refreshBaseline = meta.updatedAt ?? null;
            const payload = Array.isArray(data) ? data : data?.targets ?? data?.data ?? null;

            if (!Array.isArray(payload)) {
                throw new AsyncRequestError(`${getAppName()} returned an unexpected raid target response.`);
            }

            if (meta.state === 'refreshing') {
                refreshing = true;

                if (payload.length > 0) {
                    targets = payload;
                    if (meta.updatedAt) {
                        updateTimestamp(meta.updatedAt);
                    }
                    renderRows(filteredTargets());
                }

                const message = 'Raid returns are still being calculated. This page will update automatically.';
                if (root.dataset.raidState === 'loading') {
                    setPanelMessage('loading', message);
                    results.toggleAttribute('hidden', targets.length === 0);
                } else {
                    showState('loading', message, { keepResults: targets.length > 0 });
                }
                scheduleRefreshPoll(meta.retryAfter);
                retryAfter = null;
                return;
            }

            refreshing = false;
            cancelRefreshPoll();
            targets = payload;
            updateTimestamp(meta.updatedAt);
            applyFilters({
                stale: meta.stale,
                refreshState: meta.state,
                requestCompleted: true,
            });
            retryAfter = meta.retryAfter;
        } catch (error) {
            refreshing = false;
            cancelRefreshPoll();
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

            if (retryAfter !== null && !refreshing) {
                startCooldown(retryAfter);
            }
        }
    };

    const checkAvailability = async (target, row, detailRow, button) => {
        const targetId = firstNumber(target.nation?.id, target.id);
        const template = String(root.dataset.raidAvailabilityEndpoint ?? '').trim();

        if (targetId === null) {
            renderAvailability(row, detailRow, { ...target, availability: { eligible: false, reasons: ['Target nation ID is unavailable.'] } });
            return;
        }

        if (template === '') {
            const unavailable = {
                ...target,
                availability: {
                    eligible: null,
                    status: 'unavailable',
                    reasons: ['Availability checks are not configured for this page.'],
                },
            };
            renderAvailability(row, detailRow, unavailable);
            return;
        }

        setButtonBusy(button, true, 'Checking…');

        try {
            const nationId = encodeURIComponent(Number(nationInput.value));
            const encodedTargetId = encodeURIComponent(targetId);
            const endpoint = template
                .replaceAll('__NATION__', nationId)
                .replaceAll('__TARGET__', encodedTargetId)
                .replaceAll('{nation_id}', nationId)
                .replaceAll('{target_id}', encodedTargetId);
            const { data } = await requestJson(endpoint);
            const payload = data?.availability ?? data?.data?.availability ?? data?.data ?? data;

            if (!payload || typeof payload !== 'object') {
                throw new AsyncRequestError('The availability response was incomplete.');
            }

            target.availability = payload;
            renderAvailability(row, detailRow, target);
            announce(`${target.nation?.leader_name ?? 'Target'} availability checked: ${availabilityStatus(target)}.`);
        } catch (error) {
            const requestError = error instanceof AsyncRequestError
                ? error
                : new AsyncRequestError('Availability could not be checked.');
            const reasons = detailRow.querySelector('[data-raid-availability-reasons]');

            if (reasons instanceof HTMLElement) {
                reasons.hidden = false;
                reasons.textContent = requestError.message;
            }
            announce(requestError.message);
        } finally {
            setButtonBusy(button, false);
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
                control.value = key === 'sort' ? 'expected_net' : '';
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
        if (document.visibilityState === 'visible' && refreshing && !requestInFlight && refreshPollTimeout === null) {
            scheduleRefreshPoll(2);
        }

        if (
            document.visibilityState === 'visible'
            && updatedAt instanceof Date
            && Date.now() - updatedAt.getTime() >= FRESH_FOR_MS
            && targets.length > 0
            && !requestInFlight
            && !refreshing
        ) {
            markAgedAvailability();
            showState('stale', 'This page was paused and the target data is now older than 5 minutes. Refresh before acting.', {
                keepResults: true,
            });
        }
    });

    loadTargets();
};

export const initRaidFinders = (root = document) => {
    root.querySelectorAll('[data-raid-finder]').forEach(initializeRaidFinder);
};
