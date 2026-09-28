// Chart.js for the pages that draw charts. Components load it through Livewire's @assets, so it
// is bundled and version-locked by package-lock.json instead of fetched from a CDN on every page.
import Chart from 'chart.js/auto';

window.Chart = Chart;

// Colours come from the design tokens in app.css, read at draw time, so every chart follows the
// light/dark theme. Series use the validated slot order --color-series-1…8; status colours use the
// status tokens. A few report services still send legacy hex colours; they map onto tokens here.
const LEGACY_COLOURS = {
    '#a32d2d': 'danger',
    '#b7791f': 'warning',
    '#21633c': 'success',
    '#185fa5': 'series-1',
    '#0e7490': 'series-3',
    '#0f766e': 'series-3',
    '#6b46c1': 'series-7',
    '#66758b': 'muted',
    '#475569': 'muted',
};

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function colour(ref, index = 0) {
    if (! ref) {
        return token(`--color-series-${(index % 8) + 1}`);
    }

    const key = LEGACY_COLOURS[String(ref).toLowerCase()] || String(ref);

    if (key.startsWith('#') || key.startsWith('rgb')) {
        return key;
    }

    return token(`--color-${key}`) || key;
}

function withAlpha(value, alpha) {
    const hex = value.replace('#', '');

    if (hex.length !== 6) {
        return value;
    }

    const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function plain(value) {
    return JSON.parse(JSON.stringify(value ?? null));
}

function readPath(source, path) {
    if (! path) {
        return source;
    }

    return String(path).split('.').reduce((node, key) => (node == null ? undefined : node[key]), source);
}

function formatter(unit) {
    const number = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 1 });

    if (unit === '%') {
        return (value) => `${number(value)}%`;
    }

    if (unit === 'GHS') {
        return (value) => `GHS ${number(value)}`;
    }

    return unit ? (value) => `${number(value)} ${unit}` : number;
}

// Total in the middle of a doughnut.
const centerTotal = {
    id: 'gwlCenterTotal',
    afterDraw(chart, args, options) {
        if (! options?.enabled || chart.config.type !== 'doughnut') {
            return;
        }

        const meta = chart.getDatasetMeta(0);

        if (! meta?.data?.length) {
            return;
        }

        const total = (chart.data.datasets[0]?.data || []).reduce((sum, value) => sum + Number(value || 0), 0);
        const { x, y } = meta.data[0];
        const { ctx } = chart;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = token('--color-ink');
        ctx.font = `700 22px ${token('--font-sans') || 'sans-serif'}`;
        ctx.fillText(total.toLocaleString(), x, y - (options.caption ? 8 : 0));

        if (options.caption) {
            ctx.fillStyle = token('--color-ink-3');
            ctx.font = `500 12px ${token('--font-sans') || 'sans-serif'}`;
            ctx.fillText(options.caption, x, y + 14);
        }

        ctx.restore();
    },
};

function buildConfig(config, labels, series) {
    const type = config.type || 'line';
    const isDoughnut = type === 'doughnut';
    const isLine = type === 'line' || type === 'area';
    const horizontal = type === 'hbar';
    const format = formatter(config.unit);
    const surface = token('--color-surface');
    const grid = token('--color-chart-grid');
    const axis = token('--color-chart-axis');
    const ink = token('--color-ink');
    const ink2 = token('--color-ink-2');
    const ink3 = token('--color-ink-3');
    const line = token('--color-line');
    const font = token('--font-sans') || 'sans-serif';

    // With a colour map, categories it doesn't name stay neutral so they can't borrow a mapped colour.
    const mapped = (category) => (config.colorMap ? (config.colorMap[category] ?? 'muted') : undefined);

    const datasets = series.map((item, index) => {
        const base = colour(item.color, index);

        if (isDoughnut) {
            const slices = (item.data || []).map((_, slice) => colour(mapped(labels[slice]) ?? item.colors?.[slice], slice));

            return {
                label: item.label,
                data: item.data || [],
                backgroundColor: slices,
                hoverBackgroundColor: slices,
                borderColor: surface,
                borderWidth: 2,
                borderRadius: 3,
                hoverOffset: 4,
            };
        }

        if (isLine) {
            return {
                label: item.label,
                data: item.data || [],
                borderColor: base,
                backgroundColor: type === 'area'
                    ? (context) => {
                        const { chartArea, ctx } = context.chart;

                        if (! chartArea) {
                            return withAlpha(base, 0.12);
                        }

                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, withAlpha(base, 0.22));
                        gradient.addColorStop(1, withAlpha(base, 0));

                        return gradient;
                    }
                    : base,
                fill: type === 'area' ? 'origin' : false,
                borderWidth: 2,
                tension: 0.35,
                cubicInterpolationMode: 'monotone',
                pointRadius: (item.data || []).length === 1 ? 4 : 0,
                pointHoverRadius: 5,
                pointBackgroundColor: base,
                pointHoverBorderColor: surface,
                pointHoverBorderWidth: 2,
            };
        }

        const perPoint = (Array.isArray(item.colors) && item.colors.length > 0) || (config.colorMap && series.length === 1);

        return {
            label: item.label,
            data: item.data || [],
            backgroundColor: perPoint
                ? (item.data || []).map((_, point) => colour(mapped(labels[point]) ?? item.colors?.[point] ?? item.color, index))
                : base,
            borderRadius: config.stacked ? 0 : 4,
            borderSkipped: 'start',
            borderColor: surface,
            borderWidth: config.stacked ? { [horizontal ? 'right' : 'top']: 2 } : 0,
            maxBarThickness: 28,
            categoryPercentage: 0.72,
            barPercentage: 0.9,
        };
    });

    const showLegend = config.legend === false
        ? false
        : (isDoughnut || series.length > 1 || config.legend);

    const wholeNumbers = series.every((item) => (item.data || []).every((value) => Number.isInteger(Number(value))));

    const valueAxis = {
        beginAtZero: true,
        min: config.min ?? undefined,
        max: config.max ?? undefined,
        stacked: Boolean(config.stacked),
        grid: { color: grid, drawTicks: false },
        border: { display: false },
        // Axis ticks stay plain numbers (the unit is in the title and tooltip), except percentages.
        ticks: { color: ink3, padding: 8, maxTicksLimit: 6, precision: wholeNumbers ? 0 : undefined, callback: (value) => (config.unit === '%' ? format(value) : formatter(null)(value)) },
    };

    const categoryAxis = {
        stacked: Boolean(config.stacked),
        grid: { display: false },
        border: { color: axis },
        ticks: {
            color: ink3,
            padding: 6,
            autoSkip: true,
            maxRotation: 0,
            // Long names (districts, offices) are shortened on the axis; tooltips and the table keep them whole.
            callback(value) {
                const text = String(this.getLabelForValue(value) ?? '');
                const limit = horizontal ? 18 : 12;

                return text.length > limit ? `${text.slice(0, limit - 1)}…` : text;
            },
        },
    };

    return {
        type: isDoughnut ? 'doughnut' : (isLine ? 'line' : 'bar'),
        data: { labels, datasets },
        plugins: [centerTotal],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion() ? false : { duration: 400 },
            indexAxis: horizontal ? 'y' : 'x',
            cutout: isDoughnut ? '68%' : undefined,
            interaction: isDoughnut ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
            layout: { padding: isDoughnut ? 4 : { top: 6, right: 8 } },
            font: { family: font, size: 12 },
            scales: isDoughnut ? {} : (horizontal ? { x: valueAxis, y: categoryAxis } : { x: categoryAxis, y: valueAxis }),
            plugins: {
                legend: {
                    display: Boolean(showLegend),
                    position: config.legend === 'bottom' || config.legend === 'right' ? config.legend : (isDoughnut ? 'right' : 'bottom'),
                    align: isDoughnut ? 'center' : 'start',
                    labels: {
                        color: ink2,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        boxHeight: 8,
                        padding: 14,
                        font: { family: font, size: 12 },
                    },
                },
                tooltip: {
                    backgroundColor: surface,
                    titleColor: ink,
                    bodyColor: ink2,
                    borderColor: line,
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 12,
                    boxPadding: 4,
                    usePointStyle: true,
                    titleFont: { family: font, size: 13, weight: '700' },
                    bodyFont: { family: font, size: 13 },
                    callbacks: {
                        label(context) {
                            const value = context.parsed?.x !== undefined && horizontal ? context.parsed.x : (context.parsed?.y ?? context.parsed);

                            if (isDoughnut) {
                                const total = context.dataset.data.reduce((sum, item) => sum + Number(item || 0), 0);
                                const share = total ? Math.round((Number(context.parsed) / total) * 100) : 0;

                                return ` ${context.label}: ${format(context.parsed)} (${share}%)`;
                            }

                            return ` ${context.dataset.label ? `${context.dataset.label}: ` : ''}${format(value)}`;
                        },
                    },
                },
                gwlCenterTotal: { enabled: Boolean(config.center), caption: config.centerCaption || '' },
            },
        },
    };
}

function registerChart(Alpine) {
    // Chart instances live on the DOM node, outside Alpine's reactive proxies.
    Alpine.data('chart', (config = {}) => ({
        showTable: false,
        labels: config.labels || [],
        series: config.series || [],

        init() {
            this.draw();

            this.onTheme = () => this.draw();
            window.addEventListener('gwl:theme-changed', this.onTheme);

            if (config.event) {
                this.onData = (event) => {
                    const detail = event.detail?.[config.detailKey || 'charts'] ?? event.detail;
                    const source = readPath(detail, config.source);

                    if (source) {
                        this.setData(source);
                    }
                };
                window.addEventListener(config.event, this.onData);
            }

            if (config.watch && this.$wire) {
                this.$wire.$watch(config.watch, (value) => {
                    const source = readPath(value, config.source);

                    if (source) {
                        this.setData(source);
                    }
                });
            }
        },

        destroy() {
            this.$el._gwlChart?.destroy();
            this.$el._gwlChart = null;
            window.removeEventListener('gwl:theme-changed', this.onTheme);

            if (this.onData) {
                window.removeEventListener(config.event, this.onData);
            }
        },

        setData(source) {
            this.labels = source.labels || [];
            this.series = this.series.map((item) => ({
                ...item,
                data: item.key ? (source[item.key] || []) : item.data,
                colors: item.colorsKey ? (source[item.colorsKey] || []) : item.colors,
            }));
            this.draw();
        },

        draw() {
            if (! window.Chart || ! this.$refs.canvas) {
                return;
            }

            this.$el._gwlChart?.destroy();
            this.$el._gwlChart = new window.Chart(
                this.$refs.canvas,
                buildConfig(config, plain(this.labels) || [], plain(this.series) || []),
            );
        },

        get isEmpty() {
            return this.series.every((item) => (item.data || []).every((value) => ! Number(value)));
        },

        get tableRows() {
            return this.labels.map((label, index) => ({
                label,
                values: this.series.map((item) => formatter(config.unit)((item.data || [])[index])),
            }));
        },
    }));
}

if (window.Alpine) {
    registerChart(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => registerChart(window.Alpine));
}
