<script>
    (() => {
        const storageKey = 'nexus-theme';
        const defaultMode = 'auto';
        const systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)');

        const normalize = (mode) => {
            if (mode === 'light' || mode === 'night' || mode === 'auto') {
                return mode;
            }

            return defaultMode;
        };

        const resolve = (mode) => {
            if (mode === 'light' || mode === 'night') {
                return mode;
            }

            return systemDarkQuery.matches ? 'night' : 'light';
        };

        const apply = (mode, broadcast = false) => {
            const normalizedMode = normalize(mode);
            const theme = resolve(normalizedMode);

            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dataset.theme = theme;
            document.documentElement.dataset.themeMode = normalizedMode;
            document.documentElement.style.colorScheme = theme === 'night' ? 'dark' : 'light';

            if (broadcast) {
                window.dispatchEvent(new CustomEvent('nexus:theme-changed', {
                    detail: { mode: normalizedMode, theme },
                }));
            }

            return theme;
        };

        const read = () => {
            try {
                return normalize(window.localStorage.getItem(storageKey) ?? defaultMode);
            } catch (error) {
                return defaultMode;
            }
        };

        const set = (mode) => {
            const normalizedMode = normalize(mode);

            try {
                if (normalizedMode === defaultMode) {
                    window.localStorage.removeItem(storageKey);
                } else {
                    window.localStorage.setItem(storageKey, normalizedMode);
                }
            } catch (error) {
                // The selected mode still applies for this page when storage is unavailable.
            }

            apply(normalizedMode, true);
        };

        window.NexusTheme = Object.freeze({
            storageKey,
            defaultMode,
            systemDarkQuery,
            normalize,
            resolve,
            apply,
            read,
            set,
        });

        apply(read());
    })();
</script>
