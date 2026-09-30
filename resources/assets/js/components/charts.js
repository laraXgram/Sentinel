import { html, raw, esc } from '../core/dom.js';
import * as fmt from '../core/format.js';

/**
 * Chart series colors. Hues are assigned to entities in a fixed order and
 * never cycled: anything past the eighth entity folds into "Other".
 */
export const SERIES = Array.from({ length: 8 }, (_, index) => `var(--series-${index + 1})`);
export const OTHER = 'var(--series-other)';

const UPDATE_COLORS = {
    message: SERIES[0],
    callback_query: SERIES[1],
    inline_query: SERIES[2],
    edited_message: SERIES[3],
    my_chat_member: SERIES[4],
    channel_post: SERIES[5],
    chosen_inline_result: SERIES[6],
    pre_checkout_query: SERIES[7],
};

/**
 * The fixed color of an update type, so it matches across every chart.
 */
export function updateColor(type) {
    return UPDATE_COLORS[type] || OTHER;
}

const SVG_NS = 'http://www.w3.org/2000/svg';

function niceStep(value, integer) {
    if (value <= 0) return 1;

    const exponent = Math.floor(Math.log10(value));
    const fraction = value / 10 ** exponent;
    const nice = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10;
    const step = nice * 10 ** exponent;

    return integer ? Math.max(1, Math.ceil(step)) : step;
}

/**
 * Round tick values from zero to just above the maximum.
 */
function ticks(max, integer = true, count = 4) {
    const step = niceStep((max || 1) / count, integer);
    const steps = Math.max(1, Math.ceil((max || 1) / step));

    return Array.from({ length: steps + 1 }, (_, index) => step * index);
}

/**
 * Base class that sizes a chart to its container and redraws on resize.
 */
class Chart {
    constructor(element, options) {
        this.element = element;
        this.options = options;
        this.element.classList.add('chart');
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'chart-tooltip';

        this.observer = new ResizeObserver(() => this.draw());
        this.observer.observe(this.element);

        this.draw();
    }

    get width() {
        return Math.max(200, this.element.clientWidth);
    }

    destroy() {
        this.observer.disconnect();
    }

    showTooltip(content, x, y) {
        this.tooltip.innerHTML = String(content);
        this.tooltip.classList.add('show');

        const width = this.tooltip.offsetWidth;
        const left = x + 16 + width > this.width ? x - width - 16 : x + 16;

        this.tooltip.style.left = `${Math.max(0, left)}px`;
        this.tooltip.style.top = `${Math.max(0, y - 20)}px`;
    }

    hideTooltip() {
        this.tooltip.classList.remove('show');
    }
}

/**
 * Line / area chart over time buckets with a crosshair tooltip.
 */
class TimeChart extends Chart {
    draw() {
        const { buckets = [], series = [], height = 260, format = fmt.compact, period = 60, stacked = false, bars = false, integer = format === fmt.compact } = this.options;
        const width = this.width;

        if (!buckets.length || !series.length || series.every((s) => s.values.every((v) => !v))) {
            this.element.innerHTML = `<div class="chart-empty" style="height:${height}px">No activity in this period</div>`;
            return;
        }

        const pad = { top: 12, right: 8, bottom: 26, left: 44 };
        const plotWidth = width - pad.left - pad.right;
        const plotHeight = height - pad.top - pad.bottom;
        const count = buckets.length;

        const totals = buckets.map((_, index) => series.reduce((sum, s) => sum + (Number(s.values[index]) || 0), 0));
        const max = stacked ? Math.max(...totals) : Math.max(...series.flatMap((s) => s.values.map((v) => Number(v) || 0)));
        const scale = ticks(max, integer);
        const top = scale[scale.length - 1];

        const x = (index) => pad.left + (bars ? (plotWidth / count) * (index + 0.5) : (plotWidth / Math.max(1, count - 1)) * index);
        const y = (value) => pad.top + plotHeight - (value / top) * plotHeight;

        let body = '';

        scale.forEach((tick) => {
            body += `<line class="grid-line" x1="${pad.left}" x2="${width - pad.right}" y1="${y(tick)}" y2="${y(tick)}"/>`;
            body += `<text x="${pad.left - 10}" y="${y(tick) + 4}" text-anchor="end">${esc(format(tick))}</text>`;
        });

        const labelEvery = Math.ceil(count / Math.max(2, Math.floor(plotWidth / 70)));

        buckets.forEach((bucket, index) => {
            if (index % labelEvery === 0 && index < count - 1) {
                body += `<text x="${x(index)}" y="${height - 6}" text-anchor="middle">${esc(fmt.bucketLabel(bucket, period))}</text>`;
            }
        });

        let marks = '';

        if (bars) {
            const slot = plotWidth / count;
            const barWidth = Math.max(2, Math.min(24, slot - 2));
            const stack = new Array(count).fill(0);

            series.forEach((s) => {
                s.values.forEach((raw, index) => {
                    const value = Number(raw) || 0;

                    if (value <= 0) return;

                    const base = stacked ? stack[index] : 0;
                    const y0 = y(base);
                    const y1 = y(base + value);
                    const h = Math.max(1, y0 - y1 - (stacked && base > 0 ? 2 : 0));
                    const radius = Math.min(4, barWidth / 2, h);
                    const left = x(index) - barWidth / 2;

                    marks += `<path d="M${left},${y1 + h} V${y1 + radius} Q${left},${y1} ${left + radius},${y1} H${left + barWidth - radius} Q${left + barWidth},${y1} ${left + barWidth},${y1 + radius} V${y1 + h} Z" style="fill:${s.color}"/>`;

                    if (stacked) stack[index] += value;
                });
            });
        } else {
            series.forEach((s, seriesIndex) => {
                const points = s.values.map((value, index) => [x(index), y(Number(value) || 0)]);
                const line = points.map(([px, py], index) => `${index ? 'L' : 'M'}${px.toFixed(1)},${py.toFixed(1)}`).join(' ');
                const id = `grad-${Math.random().toString(36).slice(2, 8)}`;

                if (series.length === 1 || s.area) {
                    marks += `<defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:${s.color};stop-opacity:.22"/><stop offset="1" style="stop-color:${s.color};stop-opacity:0"/></linearGradient></defs>`;
                    marks += `<path d="${line} L${x(count - 1)},${y(0)} L${x(0)},${y(0)} Z" fill="url(#${id})"/>`;
                }

                marks += `<path class="line" d="${line}" style="stroke:${s.color}"${s.dashed ? ' stroke-dasharray="4 4"' : ''} data-series="${seriesIndex}"/>`;
            });
        }

        body += `<line class="baseline" x1="${pad.left}" x2="${width - pad.right}" y1="${y(0)}" y2="${y(0)}"/>`;

        this.element.innerHTML = `<svg xmlns="${SVG_NS}" viewBox="0 0 ${width} ${height}" height="${height}"><g class="axis">${body}</g>${marks}<g class="hover"></g><rect class="hit" x="${pad.left}" y="0" width="${plotWidth}" height="${height}"/></svg>`;
        this.element.appendChild(this.tooltip);

        const svg = this.element.querySelector('svg');
        const hover = svg.querySelector('.hover');
        const hit = svg.querySelector('.hit');

        const move = (event) => {
            const rect = svg.getBoundingClientRect();
            const px = event.clientX - rect.left;
            const index = bars
                ? Math.min(count - 1, Math.max(0, Math.floor((px - pad.left) / (plotWidth / count))))
                : Math.min(count - 1, Math.max(0, Math.round((px - pad.left) / (plotWidth / Math.max(1, count - 1)))));

            const cx = x(index);
            let overlay = `<line class="crosshair" x1="${cx}" x2="${cx}" y1="${pad.top}" y2="${y(0)}"/>`;

            if (!bars) {
                series.forEach((s) => {
                    overlay += `<circle class="marker" cx="${cx}" cy="${y(Number(s.values[index]) || 0)}" r="4.5" style="fill:${s.color}"/>`;
                });
            }

            hover.innerHTML = overlay;

            const rows = series
                .map((s) => ({ ...s, value: s.values[index] }))
                .filter((s) => !(bars && stacked && !Number(s.value)));

            this.showTooltip(html`
                <div class="tt-title">${fmt.bucketTitle(buckets[index], period)}</div>
                ${rows.length ? rows.map((s) => html`<div class="tt-row"><span class="swatch" style="background:${raw(s.color)}"></span>${s.name}<strong>${s.value === null || s.value === undefined ? '—' : format(s.value)}</strong></div>`) : html`<div class="tt-row">No activity</div>`}
                ${stacked && series.length > 1 ? html`<div class="tt-row" style="border-top:1px solid var(--border);margin-top:4px;padding-top:6px">Total<strong>${format(totals[index])}</strong></div>` : ''}`, cx, event.clientY - rect.top);
        };

        hit.addEventListener('mousemove', move);
        hit.addEventListener('mouseleave', () => {
            hover.innerHTML = '';
            this.hideTooltip();
        });
    }
}

/**
 * Donut chart of shares with a total in the middle.
 */
class DonutChart extends Chart {
    draw() {
        const { items = [], size = 180, label = 'total', format = fmt.compact } = this.options;
        const total = items.reduce((sum, item) => sum + (Number(item.value) || 0), 0);

        if (!total) {
            this.element.innerHTML = `<div class="chart-empty" style="height:${size}px">No data yet</div>`;
            return;
        }

        const radius = size / 2 - 12;
        const center = size / 2;
        const circumference = 2 * Math.PI * radius;
        const gap = items.length > 1 ? 3 : 0;
        let offset = 0;
        let arcs = '';

        items.forEach((item, index) => {
            const length = (Number(item.value) / total) * circumference;
            const visible = Math.max(0.5, length - gap);

            arcs += `<circle cx="${center}" cy="${center}" r="${radius}" fill="none" style="stroke:${item.color}" stroke-width="18" stroke-dasharray="${visible} ${circumference - visible}" stroke-dashoffset="${-offset}" transform="rotate(-90 ${center} ${center})" data-index="${index}" stroke-linecap="butt"/>`;
            offset += length;
        });

        this.element.innerHTML = `<svg xmlns="${SVG_NS}" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" style="width:${size}px">${arcs}<text class="donut-center" x="${center}" y="${center + 4}" text-anchor="middle">${esc(format(total))}</text><text class="donut-label" x="${center}" y="${center + 22}" text-anchor="middle">${esc(label)}</text></svg>`;
        this.element.appendChild(this.tooltip);

        this.element.querySelectorAll('circle[data-index]').forEach((arc) => {
            arc.style.cursor = 'pointer';

            arc.addEventListener('mousemove', (event) => {
                const item = items[Number(arc.dataset.index)];
                const rect = this.element.getBoundingClientRect();

                this.showTooltip(html`<div class="tt-row"><span class="swatch" style="background:${raw(item.color)}"></span>${item.label}<strong>${format(item.value)} · ${fmt.percent((item.value / total) * 100)}</strong></div>`, event.clientX - rect.left, event.clientY - rect.top);
            });

            arc.addEventListener('mouseleave', () => this.hideTooltip());
        });
    }

    get width() {
        return this.options.size || 180;
    }
}

/**
 * Mount the charts declared in a rendered page.
 *
 * Elements carry `data-chart="<key>"`; `configs[key]` holds the options.
 */
export function mountCharts(root, configs) {
    const charts = [];

    root.querySelectorAll('[data-chart]').forEach((element) => {
        const config = configs[element.dataset.chart];

        if (!config) return;

        charts.push(config.type === 'donut' ? new DonutChart(element, config) : new TimeChart(element, config));
    });

    return () => charts.forEach((chart) => chart.destroy());
}

/**
 * A static sparkline for metric tiles.
 */
export function sparkline(values, color = 'var(--brand-500)', height = 36) {
    const points = (values || []).map((value) => Number(value) || 0);

    if (points.length < 2 || points.every((value) => value === 0)) {
        return raw(`<svg viewBox="0 0 100 ${height}" preserveAspectRatio="none" width="100%" height="${height}"><line x1="0" x2="100" y1="${height - 1}" y2="${height - 1}" style="stroke:var(--border)" stroke-width="1"/></svg>`);
    }

    const max = Math.max(...points, 1);
    const step = 100 / (points.length - 1);
    const line = points.map((value, index) => `${index ? 'L' : 'M'}${(index * step).toFixed(2)},${(height - 2 - (value / max) * (height - 6)).toFixed(2)}`).join(' ');
    const id = `spark-${Math.random().toString(36).slice(2, 8)}`;

    return raw(`<svg viewBox="0 0 100 ${height}" preserveAspectRatio="none" width="100%" height="${height}">
        <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:${color};stop-opacity:.2"/><stop offset="1" style="stop-color:${color};stop-opacity:0"/></linearGradient></defs>
        <path d="${line} L100,${height} L0,${height} Z" fill="url(#${id})"/>
        <path d="${line}" fill="none" style="stroke:${color}" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
    </svg>`);
}

export function legend(items) {
    return html`<div class="legend">${items.map((item) => html`<span class="legend-item"><span class="swatch" style="background:${raw(item.color)}"></span>${item.label}${item.value !== undefined ? html` <strong class="strong">${item.value}</strong>` : ''}</span>`)}</div>`;
}

/**
 * Fold series past the given count into one "Other" series.
 */
export function foldSeries(entries, limit = 5, colorOf = () => OTHER) {
    const sorted = [...entries].sort((a, b) => b.total - a.total);
    const kept = sorted.slice(0, limit).map((entry) => ({ ...entry, color: colorOf(entry.name) }));
    const rest = sorted.slice(limit);

    if (rest.length) {
        const length = rest[0].values.length;

        kept.push({
            name: 'Other',
            color: OTHER,
            total: rest.reduce((sum, entry) => sum + entry.total, 0),
            values: Array.from({ length }, (_, index) => rest.reduce((sum, entry) => sum + (Number(entry.values[index]) || 0), 0)),
        });
    }

    return kept;
}
