<script>
    (() => {
        const themeStorageKey = 'nexus-theme';
        const defaultThemeMode = 'auto';

        const normalizeThemeMode = (mode) => {
            if (mode === 'light' || mode === 'night' || mode === 'auto') {
                return mode;
            }

            return defaultThemeMode;
        };

        const resolveThemeMode = (mode) => {
            if (mode === 'light' || mode === 'night') {
                return mode;
            }

            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'light';
        };

        const applyTheme = (mode) => {
            const resolvedThemeMode = normalizeThemeMode(mode);
            const theme = resolveThemeMode(resolvedThemeMode);

            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dataset.theme = theme;
            document.documentElement.dataset.themeMode = resolvedThemeMode;
            document.documentElement.style.colorScheme = theme === 'night' ? 'dark' : 'light';
        };

        try {
            applyTheme(window.localStorage.getItem(themeStorageKey) ?? defaultThemeMode);
        } catch (error) {
            applyTheme(defaultThemeMode);
        }
    })();
</script>
