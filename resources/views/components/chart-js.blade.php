@once
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
            text: cssColor('--color-base-content', '#191c28'),
            muted: cssColor('--nexus-text-muted', `color-mix(in oklch, ${cssColor('--color-base-content', '#191c28')} 68%, transparent)`),
            grid: `color-mix(in oklch, ${cssColor('--color-base-content', '#191c28')} 13%, transparent)`,
            surface: cssColor('--color-base-100', '#ffffff'),
            primary: cssColor('--color-primary', '#475194'),
            secondary: cssColor('--color-secondary', '#a87621'),
            success: cssColor('--color-success', '#2f7d45'),
            warning: cssColor('--color-warning', '#c28c24'),
            error: cssColor('--color-error', '#b53a35'),
            info: cssColor('--color-info', '#347ba6'),
        });

        const semanticSeries = ['primary', 'secondary', 'success', 'info', 'warning', 'error'];
        const seriesLinePatterns = [[], [7, 3], [2, 3], [10, 3, 2, 3], [1, 3], [12, 4]];
        const seriesPointStyles = ['circle', 'rect', 'triangle', 'rectRot', 'star', 'crossRot'];
        const maxInlineRows = 50;

        const chartValue = (raw) => {
            if (raw === null || raw === undefined) {
                return '';
            }

            if (typeof raw !== 'object') {
                return raw;
            }

            for (const key of ['y', 'r', 'value', 'x']) {
                if (raw[key] !== undefined && raw[key] !== null) {
                    return raw[key];
                }
            }

            return JSON.stringify(raw);
        };

        const chartRows = (chart) => {
            const labels = Array.isArray(chart.data?.labels) ? chart.data.labels : [];
            const datasets = Array.isArray(chart.data?.datasets) ? chart.data.datasets : [];
            const rowCount = Math.max(
                labels.length,
                ...datasets.map((dataset) => Array.isArray(dataset.data) ? dataset.data.length : 0),
                0,
            );

            return Array.from({ length: rowCount }, (_, index) => ({
                label: labels[index] ?? `Item ${index + 1}`,
                values: datasets.map((dataset) => chartValue(dataset.data?.[index])),
            }));
        };

        const datasetLabels = (chart) => (chart.data?.datasets ?? []).map(
            (dataset, index) => dataset.label || `Series ${index + 1}`,
        );

        const displayChartValue = (value) => {
            if (typeof value === 'number' && Number.isFinite(value)) {
                return new Intl.NumberFormat(document.documentElement.lang || navigator.language || 'en', {
                    maximumFractionDigits: 2,
                }).format(value);
            }

            return value === '' ? 'Not recorded' : String(value);
        };

        const chartSummary = (chart, rows, labels) => {
            if (rows.length === 0 || labels.length === 0) {
                return 'No chart data is currently available.';
            }

            const latest = rows.at(-1);
            const latestValues = labels
                .map((label, index) => `${label}: ${displayChartValue(latest.values[index])}`)
                .join('; ');

            return `${labels.length} ${labels.length === 1 ? 'series' : 'series'} across ${rows.length} ${rows.length === 1 ? 'point' : 'points'}. Latest (${latest.label}): ${latestValues}.`;
        };

        const safeCsvCell = (value) => {
            let text = value === null || value === undefined ? '' : String(value);

            if (/^\s*[=+\-@]/.test(text)) {
                text = `'${text}`;
            }

            return `"${text.replaceAll('"', '""')}"`;
        };

        const chartCsv = (rows, labels) => [
            ['Label', ...labels],
            ...rows.map((row) => [row.label, ...row.values]),
        ].map((row) => row.map(safeCsvCell).join(',')).join('\r\n');

        const downloadChartCsv = (chart, rows, labels) => {
            const csv = chartCsv(rows, labels);
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const chartName = (chart.canvas.id || `chart-${chart.id}`)
                .replace(/[^a-z0-9_-]+/gi, '-')
                .replace(/^-+|-+$/g, '')
                .toLowerCase() || 'chart';

            link.href = url;
            link.download = `${chartName}-data.csv`;
            document.body.append(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 0);
        };

        const createChartEquivalent = (chart, rows, labels, summaryText) => {
            const wrapper = document.createElement('section');
            const summaryId = `nexus-chart-summary-${chart.id}`;
            const tableId = `nexus-chart-table-${chart.id}`;
            const wasOpen = chart.$nexusEquivalent?.querySelector('details')?.open ?? false;

            wrapper.className = 'nexus-chart-equivalent';
            wrapper.dataset.chartEquivalent = '';

            const summary = document.createElement('p');
            summary.id = summaryId;
            summary.className = 'nexus-chart-equivalent__summary';
            summary.textContent = summaryText;

            const details = document.createElement('details');
            details.open = wasOpen;

            const toggle = document.createElement('summary');
            toggle.className = 'nexus-chart-equivalent__toggle';
            toggle.textContent = `View data table (${rows.length} ${rows.length === 1 ? 'row' : 'rows'})`;
            toggle.setAttribute('aria-controls', tableId);

            const tableRegion = document.createElement('div');
            tableRegion.id = tableId;
            tableRegion.className = 'nexus-chart-equivalent__table-region';

            const table = document.createElement('table');
            table.className = 'nexus-chart-equivalent__table';

            const caption = document.createElement('caption');
            caption.className = 'sr-only';
            caption.textContent = `${chart.canvas.getAttribute('aria-label') || chart.canvas.id || 'Chart'} data`;
            table.append(caption);

            const head = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Label', ...labels].forEach((label) => {
                const heading = document.createElement('th');
                heading.scope = 'col';
                heading.textContent = label;
                headerRow.append(heading);
            });
            head.append(headerRow);
            table.append(head);

            const body = document.createElement('tbody');
            rows.slice(0, maxInlineRows).forEach((row) => {
                const tableRow = document.createElement('tr');
                const labelHeading = document.createElement('th');
                labelHeading.scope = 'row';
                labelHeading.textContent = String(row.label);
                tableRow.append(labelHeading);

                row.values.forEach((value) => {
                    const cell = document.createElement('td');
                    cell.textContent = displayChartValue(value);
                    tableRow.append(cell);
                });

                body.append(tableRow);
            });
            table.append(body);
            tableRegion.append(table);

            if (rows.length > maxInlineRows) {
                const truncatedNote = document.createElement('p');
                truncatedNote.className = 'nexus-chart-equivalent__note';
                truncatedNote.textContent = `Showing the first ${maxInlineRows} rows. The CSV contains all ${rows.length} rows.`;
                tableRegion.append(truncatedNote);
            }

            details.append(toggle, tableRegion);

            const download = document.createElement('button');
            download.type = 'button';
            download.className = 'btn btn-outline btn-sm nexus-chart-equivalent__download';
            download.textContent = 'Download chart data CSV';
            download.setAttribute('aria-label', `Download ${chart.canvas.getAttribute('aria-label') || chart.canvas.id || 'chart'} data as CSV`);
            download.addEventListener('click', () => downloadChartCsv(chart, rows, labels));

            wrapper.append(summary, details, download);

            const describedBy = new Set((chart.canvas.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            describedBy.add(summaryId);
            chart.canvas.setAttribute('aria-describedby', [...describedBy].join(' '));
            chart.canvas.setAttribute('role', 'img');
            if (! chart.canvas.hasAttribute('aria-label')) {
                chart.canvas.setAttribute('aria-label', labels.length > 0 ? `${labels.join(', ')} chart` : 'Data chart');
            }

            return wrapper;
        };

        const renderChartEquivalent = (chart) => {
            const rows = chartRows(chart);
            const labels = datasetLabels(chart);
            const signature = JSON.stringify({ rows, labels });

            if (chart.$nexusEquivalentSignature === signature && chart.$nexusEquivalent?.isConnected) {
                return;
            }

            const equivalent = createChartEquivalent(chart, rows, labels, chartSummary(chart, rows, labels));

            if (chart.$nexusEquivalent?.isConnected) {
                chart.$nexusEquivalent.replaceWith(equivalent);
            } else {
                const canvasParent = chart.canvas.parentElement;
                const parentDefinesChartHeight = canvasParent && (
                    canvasParent.style.height
                    || canvasParent.style.minHeight
                    || canvasParent.style.maxHeight
                    || [...canvasParent.classList].some((className) => /^(?:(?:min|max)-)?h-/.test(className))
                );
                const anchor = parentDefinesChartHeight ? canvasParent : chart.canvas;
                anchor.insertAdjacentElement('afterend', equivalent);
            }

            chart.$nexusEquivalent = equivalent;
            chart.$nexusEquivalentSignature = signature;
        };

        const chartAccessibilityPlugin = {
            id: 'nexusAccessibleEquivalent',
            afterInit: renderChartEquivalent,
            afterUpdate: renderChartEquivalent,
            afterDestroy(chart) {
                chart.$nexusEquivalent?.remove();
            },
        };

        const applyTheme = (chart) => {
            const palette = colors();
            const options = chart.config.options ?? {};

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

            if (plugins.tooltip !== false) {
                const tooltip = plugins.tooltip ??= {};
                tooltip.backgroundColor = palette.text;
                tooltip.titleColor = palette.surface;
                tooltip.bodyColor = palette.surface;
                tooltip.borderColor = palette.grid;
            }

            (chart.data?.datasets ?? []).forEach((dataset, index) => {
                if (dataset.nexusColor && palette[dataset.nexusColor]) {
                    dataset.borderColor = palette[dataset.nexusColor];
                    dataset.backgroundColor = palette[dataset.nexusColor];
                }

                if (dataset.nexusBorderColor && palette[dataset.nexusBorderColor]) {
                    dataset.borderColor = palette[dataset.nexusBorderColor];
                }

                if (dataset.nexusValueColors) {
                    const positive = palette[dataset.nexusValueColors.positive] ?? palette.success;
                    const negative = palette[dataset.nexusValueColors.negative] ?? palette.warning;
                    dataset.backgroundColor = (context) => Number(context.raw) >= 0 ? positive : negative;
                    dataset.hoverBackgroundColor = dataset.backgroundColor;
                }

                if (dataset.nexusPalette) {
                    const itemCount = Array.isArray(dataset.data) ? dataset.data.length : 0;
                    const themedColors = Array.from(
                        { length: itemCount },
                        (_, index) => palette[semanticSeries[index % semanticSeries.length]],
                    );

                    dataset.backgroundColor = themedColors;

                    if (Array.isArray(dataset.borderColor)) {
                        dataset.borderColor = themedColors;
                    }
                }

                const chartType = dataset.type || chart.config.type;
                if (chartType === 'line') {
                    dataset.borderDash ??= seriesLinePatterns[index % seriesLinePatterns.length];
                    dataset.pointStyle ??= seriesPointStyles[index % seriesPointStyles.length];
                    if (typeof dataset.pointRadius !== 'function') {
                        dataset.pointRadius = Math.max(Number(dataset.pointRadius) || 0, 2);
                    }
                }
            });
        };

        Chart.defaults.color = colors().text;
        Chart.defaults.borderColor = colors().grid;
        Chart.register(
            {
                id: 'nexusTheme',
                beforeInit: applyTheme,
            },
            chartAccessibilityPlugin,
        );

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
@endonce
