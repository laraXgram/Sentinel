import { html, raw, esc, cls } from '../core/dom.js';
import { icon } from '../core/icons.js';
import * as fmt from '../core/format.js';

export function card({ title, sub, actions, body, flush = false, className = '', footer, bordered = false }) {
    return html`
        <section class="${cls('card', className)}">
            ${title ? html`
                <header class="${cls('card-header', bordered && 'bordered')}">
                    <div class="grow">
                        <h3 class="card-title">${title}</h3>
                        ${sub ? html`<p class="card-sub">${sub}</p>` : ''}
                    </div>
                    ${actions ? html`<div class="row">${actions}</div>` : ''}
                </header>` : ''}
            <div class="${cls('card-body', flush && 'flush', title && !bordered && !flush && 'tight')}">${body}</div>
            ${footer ? html`<footer class="card-footer">${footer}</footer>` : ''}
        </section>`;
}

/**
 * A metric tile: icon, label, headline value and change against the previous period.
 *
 * `goodWhenUp` colors the change: more updates is good, more errors is bad.
 */
export function tile({ label, value, previous, format = fmt.compact, iconName, tone = '', goodWhenUp = true, suffix = '', spark = '', href }) {
    const change = previous === undefined ? undefined : fmt.delta(value, previous);

    let badge = '';

    if (change === null) {
        badge = html`<span class="badge ${goodWhenUp ? 'success' : 'warning'}">new</span>`;
    } else if (change !== undefined && Math.abs(change) >= 0.5) {
        const up = change > 0;
        const good = up === goodWhenUp;

        badge = html`<span class="badge ${good ? 'success' : 'error'}" title="Compared to the previous period">${icon(up ? 'arrowUp' : 'arrowDown')}${fmt.percent(Math.abs(change), 0)}</span>`;
    } else if (change !== undefined) {
        badge = html`<span class="badge" title="Compared to the previous period">0%</span>`;
    }

    const body = html`
        <div class="tile-icon ${tone}">${icon(iconName)}</div>
        <div class="tile-foot">
            <div>
                <div class="tile-label">${label}</div>
                <div class="tile-value">${format(value)}${suffix ? html`<small>${suffix}</small>` : ''}</div>
            </div>
            ${badge}
        </div>
        ${spark ? html`<div class="tile-spark">${spark}</div>` : ''}`;

    return href
        ? html`<a class="card tile" href="${href}">${body}</a>`
        : html`<div class="card tile">${body}</div>`;
}

export function badge(text, tone = '', iconName = null) {
    return html`<span class="badge ${tone}">${iconName ? icon(iconName) : ''}${text}</span>`;
}

export function empty({ iconName = 'inbox', title = 'Nothing here yet', text = '', compact = false, action = '' } = {}) {
    return html`
        <div class="${cls('empty', compact && 'compact')}">
            <div class="empty-icon">${icon(iconName)}</div>
            <div class="empty-title">${title}</div>
            ${text ? html`<div class="empty-text">${text}</div>` : ''}
            ${action ? html`<div class="mt-2">${action}</div>` : ''}
        </div>`;
}

export function skeleton({ tiles = 4, blocks = 2 } = {}) {
    return html`
        <div class="page-head"><div><div class="skeleton" style="width:220px;height:28px"></div><div class="skeleton mt-2" style="width:320px;height:16px"></div></div></div>
        ${tiles ? html`<div class="grid cols-4">${Array.from({ length: tiles }, () => html`<div class="card tile"><div class="skeleton" style="width:48px;height:48px;border-radius:12px"></div><div class="skeleton mt-4" style="width:60%;height:14px"></div><div class="skeleton mt-2" style="width:40%;height:28px"></div></div>`)}</div>` : ''}
        ${Array.from({ length: blocks }, () => html`<div class="card" style="margin-top:24px"><div class="card-body"><div class="skeleton" style="width:30%;height:18px"></div><div class="skeleton mt-4" style="height:220px"></div></div></div>`)}`;
}

export function errorView(error) {
    return html`
        <div class="card">
            ${empty({
                iconName: 'alertTriangle',
                title: error?.status === 403 ? 'Not authorized' : 'Could not load this page',
                text: error?.message || String(error),
                action: html`<button class="btn outline sm" data-action="retry">${icon('refresh')} Try again</button>`,
            })}
        </div>`;
}

export function pageHead({ title, sub, tools = '', crumbs = null }) {
    return html`
        <div class="page-head">
            <div class="grow">
                ${crumbs ? html`<nav class="breadcrumb">${crumbs.map((crumb, index) => html`${index ? icon('chevronRight') : ''}${crumb.href ? html`<a href="${crumb.href}">${crumb.label}</a>` : html`<span>${crumb.label}</span>`}`)}</nav>` : ''}
                <h1 class="page-title">${title}</h1>
                ${sub ? html`<p class="page-sub">${sub}</p>` : ''}
            </div>
            ${tools ? html`<div class="page-tools">${tools}</div>` : ''}
        </div>`;
}

export function segmented(options, active, action, size = '') {
    return html`
        <div class="${cls('segmented', size)}" role="tablist">
            ${options.map(([value, label]) => html`<button type="button" class="${String(value) === String(active) ? 'active' : ''}" data-action="${action}" data-value="${value}">${label}</button>`)}
        </div>`;
}

export function props(rows) {
    return html`
        <dl class="props">
            ${rows.filter((row) => row && row[1] !== undefined && row[1] !== null && row[1] !== '').map(([label, value]) => html`<dt>${label}</dt><dd>${value}</dd>`)}
        </dl>`;
}

/**
 * A list of labelled horizontal bars, relative to the largest value.
 */
export function barList(rows, { format = fmt.compact, color = 'var(--brand-500)', empty: emptyText = 'No data for this period', href } = {}) {
    if (!rows.length) {
        return empty({ compact: true, iconName: 'activity', title: emptyText });
    }

    const max = Math.max(...rows.map((row) => Number(row.value) || 0), 1);

    return html`
        <div class="bars">
            ${rows.map((row) => {
                const label = html`<div class="bar-label">${row.swatch ? html`<span class="swatch" style="background:${raw(row.swatch)}"></span>` : ''}${row.prefix || ''}<span title="${row.title || row.label}">${row.label}</span></div>`;
                const link = href ? href(row) : null;

                return html`
                    <div class="bar-row">
                        ${link ? html`<a href="${link}">${label}</a>` : label}
                        <div class="bar-value">${format(row.value)}${row.extra ? html` <small>${row.extra}</small>` : ''}</div>
                        <div class="bar-track"><div class="bar-fill" style="width:${Math.max(2, (Number(row.value) / max) * 100).toFixed(1)}%;background:${raw(row.color || color)}"></div></div>
                    </div>`;
            })}
        </div>`;
}

export function meter(value, max = 100, { warn = 70, error = 90 } = {}) {
    const ratio = max > 0 ? Math.min(100, (Number(value) / max) * 100) : 0;
    const tone = ratio >= error ? 'error' : ratio >= warn ? 'warning' : '';

    return html`<div class="meter ${tone}"><div style="width:${ratio.toFixed(1)}%"></div></div>`;
}

export function gauge(score, status) {
    const radius = 54;
    const circumference = 2 * Math.PI * radius;
    const value = Math.max(0, Math.min(100, Number(score) || 0));
    const color = status === 'error' ? 'var(--error-500)' : status === 'warning' ? 'var(--warning-500)' : status === 'inactive' ? 'var(--gray-400)' : 'var(--success-500)';

    return html`
        <div class="gauge">
            <svg viewBox="0 0 132 132">
                <circle class="gauge-track" cx="66" cy="66" r="${radius}"/>
                <circle class="gauge-fill" cx="66" cy="66" r="${radius}" style="stroke:${raw(color)}" stroke-dasharray="${circumference.toFixed(2)}" stroke-dashoffset="${(circumference * (1 - value / 100)).toFixed(2)}"/>
            </svg>
            <div class="gauge-text"><strong>${status === 'inactive' ? '—' : value}</strong><span>health</span></div>
        </div>`;
}

const HUES = [
    ['#465fff', '#7592ff'], ['#2aabee', '#5ec3f5'], ['#12b76a', '#32d583'], ['#f79009', '#fdb022'],
    ['#ee46bc', '#f670c7'], ['#7a5af8', '#9b8afb'], ['#f04438', '#f97066'], ['#06aed4', '#22ccee'],
];

/**
 * A colored avatar with initials; the color follows the ID, like Telegram's.
 */
export function avatar(name, id, size = '', square = false) {
    const text = String(name || '?').trim();
    const initials = text.split(/\s+/).slice(0, 2).map((part) => [...part][0] || '').join('') || '?';
    const index = Math.abs(Number(String(id).replace(/\D/g, '').slice(-6)) || text.length) % HUES.length;
    const [from, to] = HUES[index];

    return html`<span class="${cls('avatar', size, square && 'square')}" style="background:linear-gradient(135deg, ${raw(to)}, ${raw(from)})">${initials}</span>`;
}

export function identity(name, sub, id, size = 'sm', href = null) {
    const inner = html`${avatar(name, id, size)}<span class="identity-text"><span class="identity-name">${name}</span>${sub ? html`<span class="identity-sub">${sub}</span>` : ''}</span>`;

    return href ? html`<a class="identity" href="${href}">${inner}</a>` : html`<span class="identity">${inner}</span>`;
}

/* ------------------------------------------------------------------
 * JSON viewer
 * ------------------------------------------------------------------ */

function jsonValue(value) {
    if (value === null) return html`<span class="z">null</span>`;
    if (typeof value === 'string') return html`<span class="s">"${value}"</span>`;
    if (typeof value === 'number') return html`<span class="n">${value}</span>`;
    if (typeof value === 'boolean') return html`<span class="b">${String(value)}</span>`;

    return html`<span>${String(value)}</span>`;
}

function jsonNode(key, value, depth, last) {
    const label = key === null ? '' : html`<span class="k">${typeof key === 'number' ? '' : `"${key}"`}</span>${typeof key === 'number' ? '' : html`<span class="p">: </span>`}`;
    const comma = last ? '' : html`<span class="p">,</span>`;

    if (value !== null && typeof value === 'object') {
        const isArray = Array.isArray(value);
        const entries = isArray ? value.map((item, index) => [index, item]) : Object.entries(value);
        const [open, close] = isArray ? ['[', ']'] : ['{', '}'];

        if (entries.length === 0) {
            return html`<div class="json-line">${label}<span class="p">${open}${close}</span>${comma}</div>`;
        }

        const preview = isArray ? `${entries.length} items` : `${entries.length} keys`;

        return html`
            <details ${depth < 2 ? 'open' : ''}>
                <summary>${label}<span class="p">${open}</span><span class="json-preview"> ${preview} ${close}</span></summary>
                ${entries.map(([childKey, childValue], index) => jsonNode(isArray ? index : childKey, childValue, depth + 1, index === entries.length - 1))}
                <div class="p">${close}${comma}</div>
            </details>`;
    }

    return html`<div class="json-line">${label}${jsonValue(value)}${comma}</div>`;
}

export function json(value, { copyable = true } = {}) {
    const id = `json-${Math.random().toString(36).slice(2, 9)}`;

    return html`
        <div style="position:relative">
            ${copyable ? html`<button class="btn xs outline" style="position:absolute;right:10px;top:10px;z-index:1" data-action="copy-json" data-target="${id}">${icon('copy')} Copy</button>` : ''}
            <div class="json" id="${id}" data-json="${JSON.stringify(value ?? null)}">${jsonNode(null, value, 0, true)}</div>
        </div>`;
}

/**
 * Lines of source code with one highlighted line.
 */
export function codeLines(lines, highlight) {
    const entries = Object.entries(lines || {});

    if (!entries.length) {
        return '';
    }

    return html`
        <div class="code-lines">
            ${entries.map(([number, line]) => html`<div class="${cls('code-line', Number(number) === Number(highlight) && 'hl')}"><span class="ln">${number}</span><span>${line}</span></div>`)}
        </div>`;
}

export function code(text, wrap = false) {
    return html`<pre class="${cls('code', wrap && 'wrap')}">${text}</pre>`;
}

/**
 * Pretty print SQL for display.
 */
export function sql(query) {
    return String(query || '')
        .replace(/\s+/g, ' ')
        .replace(/\s(from|where|and|or|order by|group by|having|limit|offset|inner join|left join|right join|join|values|set|on duplicate key update|returning)\s/gi, (match, word) => `\n${word.toUpperCase()} `)
        .trim();
}

/* ------------------------------------------------------------------
 * Toasts & modals
 * ------------------------------------------------------------------ */

export function toast(title, text = '', tone = 'success') {
    let container = document.querySelector('.toasts');

    if (!container) {
        container = document.createElement('div');
        container.className = 'toasts';
        document.body.appendChild(container);
    }

    const element = document.createElement('div');
    element.className = `toast ${tone}`;
    element.innerHTML = String(html`
        ${icon(tone === 'error' ? 'alertCircle' : tone === 'info' ? 'info' : 'checkCircle')}
        <div class="grow"><div class="toast-title">${title}</div>${text ? html`<div class="toast-text">${text}</div>` : ''}</div>
        <button class="btn xs ghost icon" aria-label="Close">${icon('x')}</button>`);

    const remove = () => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(6px)';
        element.style.transition = 'all .2s';
        setTimeout(() => element.remove(), 200);
    };

    element.querySelector('button').addEventListener('click', remove);
    container.appendChild(element);
    setTimeout(remove, tone === 'error' ? 7000 : 4000);
}

/**
 * Open a modal and resolve with the value of the pressed button.
 */
export function modal({ title, text = '', body = '', confirm = 'Confirm', cancel = 'Cancel', tone = 'primary', onConfirm = null }) {
    return new Promise((resolve) => {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = String(html`
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-head">
                    <h3 class="card-title">${title}</h3>
                    ${text ? html`<p class="card-sub" style="margin-top:6px">${text}</p>` : ''}
                </div>
                ${body ? html`<form class="modal-body" onsubmit="return false">${body}</form>` : html`<div style="height:20px"></div>`}
                <div class="modal-foot">
                    ${cancel ? html`<button class="btn outline" data-modal="cancel">${cancel}</button>` : ''}
                    <button class="btn ${tone}" data-modal="confirm">${confirm}</button>
                </div>
            </div>`);

        const close = (value) => {
            backdrop.remove();
            document.removeEventListener('keydown', onKey);
            resolve(value);
        };

        const onKey = (e) => {
            if (e.key === 'Escape') close(null);
        };

        backdrop.addEventListener('click', async (e) => {
            if (e.target === backdrop || e.target.closest('[data-modal=cancel]')) {
                close(null);
            }

            const confirmButton = e.target.closest('[data-modal=confirm]');

            if (confirmButton) {
                const form = backdrop.querySelector('form');
                const values = form ? Object.fromEntries(new FormData(form).entries()) : true;

                if (onConfirm) {
                    confirmButton.classList.add('loading');

                    try {
                        const result = await onConfirm(values, form);

                        if (result === false) {
                            confirmButton.classList.remove('loading');
                            return;
                        }
                    } catch (error) {
                        confirmButton.classList.remove('loading');
                        toast('Something went wrong', error.message, 'error');
                        return;
                    }
                }

                close(values);
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(backdrop);
        backdrop.querySelector('input, select, textarea, [data-modal=confirm]')?.focus();
    });
}

export { esc };
