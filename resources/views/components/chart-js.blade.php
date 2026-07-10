<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"
    integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
></script>
<script>
    (() => {
        if (window.NexusCharts || typeof window.Chart === 'undefined') {
            return;
        }

        const cssColor = (token, fallback) => {
            const value = getComputedStyle(document.documentElement).getPropertyValue(token).trim();

            return value || fallback;
        };

        const colors = () => ({
            text: cssColor('--color-base-content', '#24332f'),
            muted: `color-mix(in oklch, ${cssColor('--color-base-content', '#24332f')} 62%, transparent)`,
            grid: `color-mix(in oklch, ${cssColor('--color-base-content', '#24332f')} 13%, transparent)`,
            surface: cssColor('--color-base-100', '#ffffff'),
            primary: cssColor('--color-primary', '#28755f'),
            secondary: cssColor('--color-secondary', '#a87621'),
            success: cssColor('--color-success', '#2f7d45'),
            warning: cssColor('--color-warning', '#c28c24'),
            error: cssColor('--color-error', '#b53a35'),
            info: cssColor('--color-info', '#347ba6'),
        });

        const applyTheme = (chart) => {
            const palette = colors();
            const options = chart.options ?? {};

            options.color = palette.text;

            Object.values(options.scales ?? {}).forEach((scale) => {
                if (scale.grid !== false) {
                    scale.grid = { ...(scale.grid ?? {}), color: palette.grid };
                }

                if (scale.border !== false) {
                    scale.border = { ...(scale.border ?? {}), color: palette.grid };
                }

                if (scale.ticks !== false) {
                    scale.ticks = { ...(scale.ticks ?? {}), color: palette.muted };
                }

                if (scale.title) {
                    scale.title.color = palette.text;
                }

                if (scale.angleLines) {
                    scale.angleLines.color = palette.grid;
                }

                if (scale.pointLabels) {
                    scale.pointLabels.color = palette.muted;
                }
            });

            const plugins = options.plugins ??= {};

            if (plugins.legend !== false) {
                const legend = plugins.legend ??= {};
                const labels = legend.labels ??= {};
                labels.color = palette.text;
            }

            if (plugins.title) {
                plugins.title.color = palette.text;
            }
        };

        Chart.defaults.color = colors().text;
        Chart.defaults.borderColor = colors().grid;
        Chart.register({
            id: 'nexusTheme',
            beforeUpdate: applyTheme,
        });

        window.addEventListener('nexus:theme-changed', () => {
            Chart.defaults.color = colors().text;
            Chart.defaults.borderColor = colors().grid;

            Object.values(Chart.instances).forEach((chart) => {
                applyTheme(chart);
                chart.update('none');
            });
        });

        window.NexusCharts = { colors, applyTheme };
    })();
</script>
