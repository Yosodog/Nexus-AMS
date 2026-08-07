const DISPLAY_SELECTOR = '[data-nexus-time-display]';
const COUNTDOWN_SELECTOR = '[data-nexus-time-countdown]';
const instances = new Set();
const instancesByElement = new WeakMap();
let scheduledUpdate = null;

const documentLocale = () => document.documentElement.lang || navigator.language || 'en';
const monotonicNow = () => globalThis.performance?.now?.() ?? Date.now();
const numericDataValue = (value, fallback = null) => {
    if (value === undefined || value === null || value === '') {
        return fallback;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : fallback;
};

export const parseTimestamp = (value) => {
    if (typeof value !== 'string' || !/(?:Z|[+-]\d{2}:\d{2})$/i.test(value.trim())) {
        return null;
    }

    const timestamp = Date.parse(value);

    return Number.isFinite(timestamp) ? timestamp : null;
};

export const formatExactTime = (timestamp, locale = documentLocale(), timeZone = null) => {
    const options = {
        dateStyle: 'medium',
        timeStyle: 'long',
    };

    if (timeZone) {
        options.timeZone = timeZone;
    }

    return new Intl.DateTimeFormat(locale, options).format(new Date(timestamp));
};

export const formatRelativeTime = (timestamp, referenceTimestamp, locale = documentLocale()) => {
    const difference = timestamp - referenceTimestamp;
    const absoluteDifference = Math.abs(difference);
    const units = [
        ['year', 31_557_600_000],
        ['month', 2_629_800_000],
        ['week', 604_800_000],
        ['day', 86_400_000],
        ['hour', 3_600_000],
        ['minute', 60_000],
        ['second', 1_000],
    ];
    const [unit, unitMilliseconds] = units.find(([, milliseconds]) => absoluteDifference >= milliseconds) ?? ['second', 1_000];
    const magnitude = absoluteDifference < 5_000 ? 0 : Math.max(1, Math.round(absoluteDifference / unitMilliseconds));
    const amount = difference < 0 ? -magnitude : magnitude;

    return new Intl.RelativeTimeFormat(locale, {
        numeric: 'auto',
        style: 'short',
    }).format(amount, unit);
};

export const formatCountdown = (remainingMilliseconds) => {
    const remainingSeconds = Math.max(0, Math.ceil(remainingMilliseconds / 1_000));
    const days = Math.floor(remainingSeconds / 86_400);
    const hours = Math.floor((remainingSeconds % 86_400) / 3_600);
    const minutes = Math.floor((remainingSeconds % 3_600) / 60);
    const seconds = remainingSeconds % 60;
    const parts = [];

    if (days > 0) {
        parts.push(`${days}d`);
    }

    if (days > 0 || hours > 0) {
        parts.push(`${hours}h`);
    }

    if (days > 0 || hours > 0 || minutes > 0) {
        parts.push(`${String(minutes).padStart(2, '0')}m`);
    }

    parts.push(`${String(seconds).padStart(2, '0')}s`);

    return parts.join(' ');
};

const createClock = (element) => {
    const serverReference = parseTimestamp(element.dataset.serverReference);

    if (serverReference === null) {
        return null;
    }

    const wallAtStart = Date.now();

    return {
        serverAtStart: serverReference,
        wallAtStart,
        monotonicAtStart: monotonicNow(),
        initialOffset: serverReference - wallAtStart,
    };
};

const readClock = (instance) => {
    const monotonicElapsed = Math.max(0, monotonicNow() - instance.clock.monotonicAtStart);
    const wallServerTime = Date.now() + instance.clock.initialOffset;
    const monotonicServerTime = instance.clock.serverAtStart + monotonicElapsed;
    const wallClockDrift = wallServerTime - monotonicServerTime;
    const skewThreshold = numericDataValue(instance.element.dataset.clockSkewThreshold, 60) * 1_000;
    const skewed = Math.abs(instance.clock.initialOffset) > skewThreshold
        || Math.abs(wallClockDrift) > skewThreshold;

    return {
        elapsed: monotonicElapsed,
        now: Math.abs(wallClockDrift) > skewThreshold ? monotonicServerTime : wallServerTime,
        skewed,
    };
};

const announce = (instance, message) => {
    if (instance.lastAnnouncement === message) {
        return;
    }

    const status = instance.element.querySelector('[data-time-status]');

    if (status) {
        status.textContent = message;
    }

    instance.lastAnnouncement = message;
};

const isStale = (instance, clock) => {
    const staleAfter = numericDataValue(instance.element.dataset.timeStaleAfter);

    return staleAfter !== null && clock.elapsed >= staleAfter * 1_000;
};

const updateClockSkewState = (instance, clock) => {
    instance.element.dataset.clockSkewed = clock.skewed ? 'true' : 'false';
    const warning = instance.element.querySelector('[data-time-clock-warning]');

    if (!warning) {
        return;
    }

    warning.hidden = !clock.skewed;

    if (clock.skewed) {
        announce(instance, 'Device clock differs from the server. The countdown is using server time.');
    }
};

const updateDisplay = (instance, clock) => {
    const { element, timestamp } = instance;
    const relative = element.querySelector('[data-time-relative]');
    const exact = element.querySelector('[data-time-exact]');
    const tooltip = element.querySelector('[data-time-tooltip]');
    const staleIndicator = element.querySelector('[data-time-stale-indicator]');

    if (isStale(instance, clock)) {
        element.dataset.timeState = 'stale';

        if (staleIndicator) {
            staleIndicator.hidden = false;
        }

        announce(instance, 'This time display is stale. Refresh the page for a current value.');

        return false;
    }

    const relativeText = formatRelativeTime(timestamp, clock.now);
    const exactText = formatExactTime(timestamp);
    const label = element.dataset.timeLabel?.trim();
    const accessibleLabel = `${label ? `${label}. ` : ''}${relativeText}. Exact time ${exactText}.`;

    element.dataset.timeState = 'current';
    element.dataset.timeEnhanced = 'true';
    updateClockSkewState(instance, clock);

    if (relative) {
        relative.textContent = relativeText;
        relative.title = exactText;
        relative.setAttribute('aria-label', accessibleLabel);
    }

    if (tooltip) {
        tooltip.dataset.tip = exactText;
    }

    if (exact) {
        exact.textContent = exactText;
    }

    if (staleIndicator) {
        staleIndicator.hidden = true;
    }

    return true;
};

const updateCountdown = (instance, clock) => {
    const { element, timestamp } = instance;
    const countdown = element.querySelector('[data-time-countdown-value]');
    const target = element.querySelector('[data-time-countdown-target]');
    const targetText = formatExactTime(timestamp);
    const label = element.dataset.timeLabel?.trim() || 'Time remaining';

    element.dataset.timeEnhanced = 'true';
    updateClockSkewState(instance, clock);

    if (target) {
        target.textContent = targetText;
    }

    if (isStale(instance, clock)) {
        const staleText = element.dataset.timeStaleText || 'Refresh for a current countdown';

        element.dataset.timeState = 'stale';
        element.dataset.timeRemainingSeconds = '0';

        if (countdown) {
            countdown.textContent = staleText;
            countdown.setAttribute('aria-label', `${staleText}. Target ${targetText}.`);
        }

        announce(instance, `${staleText}.`);

        return false;
    }

    const remainingMilliseconds = timestamp - clock.now;

    if (remainingMilliseconds <= 0) {
        const expiredText = element.dataset.timeExpiredText || 'Target reached';

        element.dataset.timeState = 'expired';
        element.dataset.timeRemainingSeconds = '0';

        if (countdown) {
            countdown.textContent = expiredText;
            countdown.setAttribute('aria-label', `${expiredText}. Target ${targetText}.`);
        }

        announce(instance, `${expiredText}.`);

        return false;
    }

    const remainingText = formatCountdown(remainingMilliseconds);

    element.dataset.timeState = 'current';
    element.dataset.timeRemainingSeconds = String(Math.max(0, Math.ceil(remainingMilliseconds / 1_000)));

    if (countdown) {
        countdown.textContent = remainingText;
        countdown.setAttribute('aria-label', `${label}. ${remainingText} remaining. Target ${targetText}.`);
    }

    return true;
};

const updateInstance = (instance) => {
    const clock = readClock(instance);

    return instance.kind === 'countdown'
        ? updateCountdown(instance, clock)
        : updateDisplay(instance, clock);
};

const clearScheduledUpdate = () => {
    if (scheduledUpdate !== null) {
        window.clearTimeout(scheduledUpdate);
        scheduledUpdate = null;
    }
};

const scheduleNextUpdate = (delay) => {
    clearScheduledUpdate();

    if (delay === null || document.visibilityState === 'hidden') {
        return;
    }

    scheduledUpdate = window.setTimeout(refreshTimeDisplays, delay);
};

export const refreshTimeDisplays = () => {
    let hasActiveCountdown = false;
    let hasActiveDisplay = false;

    for (const instance of instances) {
        if (!instance.element.isConnected) {
            instances.delete(instance);
            continue;
        }

        const active = updateInstance(instance);

        if (active && instance.kind === 'countdown') {
            hasActiveCountdown = true;
        }

        if (active && instance.kind === 'display') {
            hasActiveDisplay = true;
        }
    }

    scheduleNextUpdate(hasActiveCountdown ? 1_000 : (hasActiveDisplay ? 30_000 : null));
};

const registerElement = (element, kind) => {
    if (instancesByElement.has(element)) {
        return;
    }

    const timestamp = parseTimestamp(kind === 'countdown'
        ? element.dataset.timeTarget
        : element.dataset.timeValue);
    const clock = createClock(element);

    if (timestamp === null || clock === null) {
        element.dataset.timeState = 'invalid';

        return;
    }

    const instance = {
        clock,
        element,
        kind,
        lastAnnouncement: '',
        timestamp,
    };

    instances.add(instance);
    instancesByElement.set(element, instance);
};

export const initializeTimeDisplays = (root = document) => {
    const displays = root.matches?.(DISPLAY_SELECTOR)
        ? [root]
        : [...root.querySelectorAll(DISPLAY_SELECTOR)];
    const countdowns = root.matches?.(COUNTDOWN_SELECTOR)
        ? [root]
        : [...root.querySelectorAll(COUNTDOWN_SELECTOR)];

    displays.forEach((element) => registerElement(element, 'display'));
    countdowns.forEach((element) => registerElement(element, 'countdown'));
    refreshTimeDisplays();
};

const resumeUpdates = () => {
    if (document.visibilityState !== 'hidden') {
        initializeTimeDisplays();
    }
};

const pauseUpdates = () => {
    if (document.visibilityState === 'hidden') {
        clearScheduledUpdate();

        return;
    }

    resumeUpdates();
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeTimeDisplays(), { once: true });
    } else {
        initializeTimeDisplays();
    }

    document.addEventListener('livewire:navigated', () => initializeTimeDisplays());
    document.addEventListener('visibilitychange', pauseUpdates);
    document.addEventListener('nexus:time-refresh', resumeUpdates);
    window.addEventListener('focus', resumeUpdates);
    window.addEventListener('pageshow', resumeUpdates);
    window.addEventListener('pagehide', clearScheduledUpdate);
}
