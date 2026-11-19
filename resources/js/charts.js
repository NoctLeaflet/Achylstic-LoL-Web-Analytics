// Chart.js components for Alpine. All numbers (LP scores, normalised radar values,
// axis ticks, colours) are prepared by the backend and handed over as JSON; this
// file only draws them.

import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    RadarController,
    RadialLinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    RadarController,
    RadialLinearScale,
    Tooltip,
);

const TEXT = '#94a3b8'; // slate-400
const TEXT_STRONG = '#cbd5e1'; // slate-300
const GRID = 'rgba(148, 163, 184, 0.15)';

Chart.defaults.color = TEXT;
Chart.defaults.borderColor = GRID;
Chart.defaults.font.family = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
Chart.defaults.plugins.tooltip.borderColor = '#334155';
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.titleColor = TEXT_STRONG;
Chart.defaults.plugins.tooltip.bodyColor = TEXT_STRONG;

const DAY_MS = 24 * 60 * 60 * 1000;

const formatDate = (ms) => new Date(ms).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

const formatDateTime = (ms) =>
    new Date(ms).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

// X-axis ticks at local midnight, spaced by a whole number of days that suits the visible range.
function dayTicks(min, max) {
    const days = (max - min) / DAY_MS;
    const step = days <= 7 ? 1 : days <= 14 ? 2 : days <= 31 ? 5 : 10;

    const tick = new Date(min);
    tick.setHours(24, 0, 0, 0);

    const ticks = [];

    while (tick.getTime() <= max) {
        ticks.push({ value: tick.getTime() });
        tick.setDate(tick.getDate() + step);
    }

    return ticks;
}

export function registerCharts(Alpine) {
    // LP over time. data = { datasets: [{ label, color, points: [{ x, y, label }] }], ticks: [{ value, label }] }
    Alpine.data('lpChart', (data) => ({
        chart: null,

        init() {
            const tickLabels = Object.fromEntries(data.ticks.map((tick) => [tick.value, tick.label]));
            const xs = data.datasets.flatMap((dataset) => dataset.points.map((point) => point.x));
            const firstX = Math.min(...xs);
            const lastX = Math.max(...xs);
            // Fit the axis to the data with a little air; a single moment gets half a day on each side.
            const padding = lastX > firstX ? (lastX - firstX) * 0.03 : DAY_MS / 2;

            this.chart = new Chart(this.$refs.canvas, {
                type: 'line',
                data: {
                    datasets: data.datasets.map((dataset) => ({
                        label: dataset.label,
                        data: dataset.points,
                        borderColor: dataset.color,
                        backgroundColor: dataset.color,
                        borderWidth: 2,
                        tension: 0,
                        pointRadius: dataset.points.length > 40 ? 0 : 3,
                        pointHoverRadius: 5,
                    })),
                },
                options: {
                    interaction: { mode: 'nearest', intersect: false },
                    scales: {
                        x: {
                            type: 'linear',
                            min: xs.length > 0 ? firstX - padding : undefined,
                            max: xs.length > 0 ? lastX + padding : undefined,
                            grid: { display: false },
                            afterBuildTicks: (scale) => {
                                scale.ticks = dayTicks(scale.min, scale.max);
                            },
                            ticks: { callback: (value) => formatDate(value) },
                        },
                        y: {
                            min: data.ticks[0]?.value,
                            max: data.ticks[data.ticks.length - 1]?.value,
                            afterBuildTicks: (scale) => {
                                scale.ticks = data.ticks.map((tick) => ({ value: tick.value }));
                            },
                            ticks: { callback: (value) => tickLabels[value] ?? '' },
                        },
                    },
                    plugins: {
                        legend: { display: data.datasets.length > 1 },
                        tooltip: {
                            callbacks: {
                                title: (items) => formatDateTime(items[0].parsed.x),
                                label: (item) => `${item.dataset.label}: ${item.raw.label}`,
                            },
                        },
                    },
                },
            });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));

    // Radar. data = { labels, datasets: [{ label, color, values (0-100), raw }] }
    Alpine.data('radarChart', (data) => ({
        chart: null,

        init() {
            this.chart = new Chart(this.$refs.canvas, {
                type: 'radar',
                data: {
                    labels: data.labels,
                    datasets: data.datasets.map((dataset) => ({
                        label: dataset.label,
                        data: dataset.values,
                        borderColor: dataset.color,
                        backgroundColor: `${dataset.color}33`,
                        pointBackgroundColor: dataset.color,
                        pointRadius: 3,
                        borderWidth: 2,
                    })),
                },
                options: {
                    scales: {
                        r: {
                            min: 0,
                            max: 100,
                            ticks: { display: false, stepSize: 25 },
                            grid: { color: GRID },
                            angleLines: { color: GRID },
                            pointLabels: { color: TEXT_STRONG, font: { size: 12 } },
                        },
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: (item) => `${item.dataset.label}: ${data.datasets[item.datasetIndex].raw[item.dataIndex]}`,
                            },
                        },
                    },
                },
            });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));

    // One bar chart for one metric. bar = { label, labels, values, colors }
    Alpine.data('barChart', (bar) => ({
        chart: null,

        init() {
            this.chart = new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels: bar.labels,
                    datasets: [
                        {
                            label: bar.label,
                            data: bar.values,
                            backgroundColor: bar.colors,
                            borderRadius: 4,
                        },
                    ],
                },
                options: {
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true },
                    },
                    plugins: { legend: { display: false } },
                },
            });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));
}
