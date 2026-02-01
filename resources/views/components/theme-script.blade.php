<script>
    (function () {
        // Function to set the theme
        function setTheme(theme) {
            const doc = document.documentElement;
            if (theme === 'night') {
                doc.setAttribute('data-theme', 'night'); // DaisyUI
                doc.setAttribute('data-bs-theme', 'dark'); // Bootstrap/AdminLTE
                localStorage.setItem('theme', 'night');
            } else {
                doc.removeAttribute('data-theme'); // DaisyUI Default (light)
                doc.removeAttribute('data-bs-theme'); // Bootstrap Default (light)
                // Optionally explicitly set light if needed:
                // doc.setAttribute('data-theme', 'light');
                // doc.setAttribute('data-bs-theme', 'light');
                localStorage.setItem('theme', 'light');
            }
            updateToggles(theme === 'night');
        }

        // Function to update all toggle inputs/buttons
        function updateToggles(isDark) {
            // For Checkbox inputs (DaisyUI swap)
            document.querySelectorAll('input[type="checkbox"]#theme-toggle').forEach(el => {
                el.checked = isDark;
            });

            // For custom buttons (AdminLTE) if we use a button with icon switching
            document.querySelectorAll('.theme-toggle-btn').forEach(el => {
                const icon = el.querySelector('i');
                if (icon) {
                    if (isDark) {
                        icon.classList.remove('bi-moon-fill');
                        icon.classList.add('bi-sun-fill');
                    } else {
                        icon.classList.remove('bi-sun-fill');
                        icon.classList.add('bi-moon-fill');
                    }
                }
            });
        }

        // 1. Init: Check localStorage or System Preference
        const savedTheme = localStorage.getItem('theme');
        const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'night' || (!savedTheme && systemDark)) {
            setTheme('night');
        } else {
            setTheme('light');
        }

        // 2. Global Event Listener for Toggles (Delegation not strictly needed but good for dynamic content)
        // We will attach listeners to specific known IDs/Classes after DOM Load just to be safe, 
        // but this script runs in HEAD so we need to wait for DOMContentLoaded for binding events.

        document.addEventListener('DOMContentLoaded', () => {
            // Sync state again in case UI loaded with default unchecked
            const currentTheme = localStorage.getItem('theme') || (systemDark ? 'night' : 'light');
            updateToggles(currentTheme === 'night');

            // Listener for DaisyUI Checkbox
            const daisyToggle = document.getElementById('theme-toggle');
            if (daisyToggle) {
                daisyToggle.addEventListener('change', function () {
                    setTheme(this.checked ? 'night' : 'light');
                });
            }

            // Listener for AdminLTE Buttons (Class-based for multiple instances if needed)
            document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const current = localStorage.getItem('theme') || 'light';
                    const next = current === 'night' ? 'light' : 'night';
                    setTheme(next);
                });
            });
        });
    })();
</script>