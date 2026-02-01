<script>
    (function () {
        const doc = document.documentElement;

        // Public API
        window.ThemeManager = {
            setTheme: function (theme, persist = false) {
                _setTheme(theme, persist);
            },
            resetTheme: function () {
                localStorage.removeItem('theme');
                const systemQuery = window.matchMedia('(prefers-color-scheme: dark)');
                _setTheme(systemQuery.matches ? 'night' : 'light', false);
            },
            getTheme: function () {
                return doc.getAttribute('data-theme');
            }
        };

        // Internal
        function _setTheme(theme, persist = false) {
            if (theme === 'night') {
                doc.setAttribute('data-theme', 'night'); // DaisyUI
                doc.setAttribute('data-bs-theme', 'dark'); // Bootstrap/AdminLTE
            } else {
                doc.removeAttribute('data-theme'); // DaisyUI Default (light)
                doc.removeAttribute('data-bs-theme'); // Bootstrap Default (light)
            }

            if (persist) {
                localStorage.setItem('theme', theme);
            }

            updateToggles(theme);
        }

        function updateToggles(activeTheme) {
            // Update Icons on the toggles to reflect current state
            document.querySelectorAll('.theme-icon-active').forEach(icon => {
                // Remove all possible icons first
                icon.classList.remove('bi-moon-fill', 'bi-sun-fill', 'bi-circle-half');

                if (activeTheme === 'night') {
                    icon.classList.add('bi-moon-fill');
                } else {
                    icon.classList.add('bi-sun-fill');
                }
            });

            // Toggle visibility for specific SVG icons (Frontend)
            const isDark = activeTheme === 'night';
            document.querySelectorAll('.theme-icon-sun').forEach(el => {
                if (isDark) {
                    el.classList.add('hidden');
                } else {
                    el.classList.remove('hidden');
                }
            });
            document.querySelectorAll('.theme-icon-moon').forEach(el => {
                if (isDark) {
                    el.classList.remove('hidden');
                } else {
                    el.classList.add('hidden');
                }
            });

            // Highlight active dropdown item
            const stored = localStorage.getItem('theme');
            const logicalState = stored ? stored : 'auto';

            document.querySelectorAll('[data-set-theme]').forEach(el => {
                const target = el.getAttribute('data-set-theme');
                if (target === logicalState) {
                    el.classList.add('active', 'bg-primary', 'text-primary-content');
                } else {
                    el.classList.remove('active', 'bg-primary', 'text-primary-content');
                }
            });
        }

        // 1. Init
        const savedTheme = localStorage.getItem('theme');
        const systemQuery = window.matchMedia('(prefers-color-scheme: dark)');

        if (savedTheme) {
            _setTheme(savedTheme, false);
        } else {
            _setTheme(systemQuery.matches ? 'night' : 'light', false);
        }

        // 2. System Listener
        systemQuery.addEventListener('change', (e) => {
            if (!localStorage.getItem('theme')) {
                _setTheme(e.matches ? 'night' : 'light', false);
            }
        });

        // 3. Bind Events (Dropdown items)
        document.addEventListener('DOMContentLoaded', () => {
            // Re-run update to ensure classes are set after DOM load
            const cur = doc.getAttribute('data-theme') || 'light';
            updateToggles(cur);

            // Bind clicks for any element with data-set-theme="light|night|auto"
            document.querySelectorAll('[data-set-theme]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const val = btn.getAttribute('data-set-theme');
                    if (val === 'auto') {
                        window.ThemeManager.resetTheme();
                    } else {
                        window.ThemeManager.setTheme(val, true);
                    }
                });
            });
        });
    })();
</script>