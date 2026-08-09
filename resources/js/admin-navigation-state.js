export const FAVORITES_KEY = 'nexus.admin.command-palette.favorites.v1';
export const RECENTS_KEY = 'nexus.admin.command-palette.recents.v1';
export const MAX_RECENTS = 8;
export const NAVIGATION_STATE_EVENT = 'nexus:admin-navigation-state-changed';

const LEGACY_NAVIGATION_IDS = new Map([
    ['grants-workspace', 'grant-programs'],
    ['war-support', 'war-aid'],
]);

export function readNavigationIds(key) {
    try {
        const value = JSON.parse(window.localStorage.getItem(key) ?? '[]');

        return Array.isArray(value)
            ? [...new Set(value
                .filter((id) => typeof id === 'string')
                .map((id) => LEGACY_NAVIGATION_IDS.get(id) ?? id))]
            : [];
    } catch {
        return [];
    }
}

export function writeNavigationIds(key, ids) {
    const normalizedIds = [...new Set(ids.filter((id) => typeof id === 'string'))];

    try {
        window.localStorage.setItem(key, JSON.stringify(normalizedIds));
    } catch {
        // Storage can be unavailable in hardened browser modes; navigation still works.
    }

    window.dispatchEvent(new CustomEvent(NAVIGATION_STATE_EVENT, {
        detail: { key, ids: normalizedIds },
    }));

    return normalizedIds;
}

export function recordRecentNavigationId(id) {
    if (typeof id !== 'string' || id === '') {
        return readNavigationIds(RECENTS_KEY);
    }

    const recents = [
        id,
        ...readNavigationIds(RECENTS_KEY).filter((recentId) => recentId !== id),
    ].slice(0, MAX_RECENTS);

    return writeNavigationIds(RECENTS_KEY, recents);
}

export function quickAccessEntries(allowedIds, limit = 5) {
    const entries = [];
    const includedIds = new Set();

    const addEntries = (ids, context) => {
        ids.forEach((id) => {
            if (entries.length >= limit || includedIds.has(id) || !allowedIds.has(id)) {
                return;
            }

            entries.push({ id, context });
            includedIds.add(id);
        });
    };

    addEntries(readNavigationIds(FAVORITES_KEY), 'favorite');
    addEntries(readNavigationIds(RECENTS_KEY), 'recent');

    return entries;
}
