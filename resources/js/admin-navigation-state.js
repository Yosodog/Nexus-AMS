export const FAVORITES_KEY = 'nexus.admin.command-palette.favorites.v1';
export const RECENTS_KEY = 'nexus.admin.command-palette.recents.v1';
export const MAX_FAVORITES = 5;
export const MAX_RECENTS = 8;
export const FAVORITE_LIMIT_MESSAGE = `You can pin up to ${MAX_FAVORITES} links. Unpin one before adding another.`;
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

export function readFavoriteNavigationIds() {
    const favoriteIds = readNavigationIds(FAVORITES_KEY);

    return favoriteIds.length > MAX_FAVORITES
        ? writeNavigationIds(FAVORITES_KEY, favoriteIds.slice(0, MAX_FAVORITES))
        : favoriteIds;
}

export function toggleFavoriteNavigationId(id) {
    const favoriteIds = readFavoriteNavigationIds();

    if (typeof id !== 'string' || id === '') {
        return { favoriteIds, outcome: 'invalid' };
    }

    if (favoriteIds.includes(id)) {
        const nextFavoriteIds = favoriteIds.filter((favoriteId) => favoriteId !== id);

        return {
            favoriteIds: writeNavigationIds(FAVORITES_KEY, nextFavoriteIds),
            outcome: 'removed',
        };
    }

    if (favoriteIds.length >= MAX_FAVORITES) {
        return { favoriteIds, outcome: 'limit' };
    }

    return {
        favoriteIds: writeNavigationIds(FAVORITES_KEY, [id, ...favoriteIds]),
        outcome: 'added',
    };
}

export function favoriteNavigationIds(allowedIds) {
    return readFavoriteNavigationIds()
        .filter((id) => allowedIds.has(id))
        .slice(0, MAX_FAVORITES);
}
