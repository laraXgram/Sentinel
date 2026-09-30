import { html } from '../core/dom.js';
import { icon } from '../core/icons.js';
import * as fmt from '../core/format.js';
import { updateMeta, personName, statusBadge, KIND_ICONS } from './telegram.js';

export const TYPES = {
    update: { label: 'Updates', single: 'Update', icon: 'inbox', tone: 'brand' },
    api_call: { label: 'API Calls', single: 'API call', icon: 'send', tone: 'info' },
    conversation: { label: 'Conversations', single: 'Conversation event', icon: 'conversation', tone: 'brand' },
    exception: { label: 'Exceptions', single: 'Exception', icon: 'bug', tone: 'error' },
    log: { label: 'Logs', single: 'Log', icon: 'log', tone: '' },
    query: { label: 'Queries', single: 'Query', icon: 'database', tone: '' },
    job: { label: 'Jobs', single: 'Job', icon: 'layers', tone: '' },
    cache: { label: 'Cache', single: 'Cache operation', icon: 'zap', tone: '' },
    command: { label: 'Commands', single: 'Command', icon: 'terminal', tone: '' },
    schedule: { label: 'Schedule', single: 'Scheduled task', icon: 'clock', tone: '' },
    request: { label: 'HTTP Requests', single: 'Request', icon: 'globe', tone: '' },
    event: { label: 'Events', single: 'Event', icon: 'radio', tone: '' },
    alert: { label: 'Alerts', single: 'Alert', icon: 'bell', tone: 'warning' },
};

const LOG_TONES = { emergency: 'error', alert: 'error', critical: 'error', error: 'error', warning: 'warning', notice: 'info', info: 'info', debug: '' };

/**
 * Describe an entry in one line: icon, title, subtitle, badge and duration.
 */
export function describe(entry) {
    const c = entry.content || {};
    const type = TYPES[entry.type] || { icon: 'info', tone: '' };

    switch (entry.type) {
        case 'update': {
            const meta = updateMeta(c.type);

            return {
                icon: KIND_ICONS[c.kind] || meta.icon,
                tone: c.status === 'failed' ? 'error' : c.status === 'unhandled' ? 'warning' : 'brand',
                title: c.text || meta.label,
                sub: `${meta.label}${c.kind && c.kind !== c.type ? ` · ${fmt.title(c.kind)}` : ''} · ${personName(c.user || c.chat)}`,
                badge: statusBadge(c.status),
                duration: c.duration,
            };
        }

        case 'api_call':
            return {
                icon: 'send',
                tone: c.ok === false ? 'error' : c.intercepted ? 'brand' : 'info',
                title: c.method,
                sub: c.ok === false ? `${c.error_code || ''} ${c.description || ''}` : c.parameters?.text || c.parameters?.caption || (c.parameters?.chat_id ? `chat ${c.parameters.chat_id}` : c.caller || ''),
                badge: c.ok === false
                    ? html`<span class="badge error">${c.retry_after ? `flood ${c.retry_after}s` : c.error_code || 'failed'}</span>`
                    : c.intercepted ? html`<span class="badge brand">dry run</span>` : html`<span class="badge success">ok</span>`,
                duration: c.duration,
            };

        case 'conversation':
            return {
                icon: 'conversation',
                tone: c.step === 'invalid' || c.step === 'cancelled' ? 'warning' : c.step === 'completed' ? 'success' : 'brand',
                title: `${c.name} · ${c.step}`,
                sub: c.question?.prompt || c.answer?.text || c.reason || (c.errors ? Object.values(c.errors).flat().join(', ') : ''),
                badge: html`<span class="badge ${c.step === 'completed' ? 'success' : c.step === 'invalid' || c.step === 'cancelled' ? 'warning' : 'brand'}">${c.step}</span>`,
            };

        case 'exception':
            return {
                icon: 'bug',
                tone: 'error',
                title: c.class?.split('\\').pop(),
                sub: c.message,
                badge: html`<span class="badge error">${c.file ? `${c.file.split('/').pop()}:${c.line}` : 'exception'}</span>`,
            };

        case 'log':
            return {
                icon: 'log',
                tone: LOG_TONES[c.level] || '',
                title: c.message,
                sub: Object.keys(c.context || {}).length ? JSON.stringify(c.context) : '',
                badge: html`<span class="badge ${LOG_TONES[c.level] || ''}">${c.level}</span>`,
            };

        case 'query':
            return {
                icon: 'database',
                tone: c.slow ? 'warning' : '',
                title: c.sql,
                sub: c.file ? `${c.file}:${c.line}` : c.connection,
                badge: c.slow ? html`<span class="badge warning">slow</span>` : '',
                duration: Number(c.time),
            };

        case 'job':
            return {
                icon: 'layers',
                tone: c.status === 'failed' ? 'error' : c.status === 'processed' ? 'success' : '',
                title: c.name?.split('\\').pop(),
                sub: `${c.connection || ''} · ${c.queue || 'default'}`,
                badge: html`<span class="badge ${c.status === 'failed' ? 'error' : c.status === 'processed' ? 'success' : 'info'}">${c.status}</span>`,
                duration: c.duration,
            };

        case 'cache':
            return {
                icon: 'zap',
                tone: c.type === 'missed' ? 'warning' : '',
                title: c.key,
                sub: c.store || '',
                badge: html`<span class="badge ${{ hit: 'success', missed: 'warning', set: 'info', forget: '' }[c.type] || ''}">${c.type}</span>`,
            };

        case 'command':
            return {
                icon: 'terminal',
                tone: c.exit_code ? 'error' : '',
                title: c.command,
                sub: Object.entries(c.arguments || {}).filter(([key]) => key !== 'command').map(([, value]) => value).join(' '),
                badge: html`<span class="badge ${c.exit_code ? 'error' : 'success'}">exit ${c.exit_code}</span>`,
                duration: c.duration,
            };

        case 'schedule':
            return {
                icon: 'clock',
                tone: c.status === 'failed' ? 'error' : '',
                title: c.command,
                sub: c.expression || '',
                badge: html`<span class="badge ${c.status === 'failed' ? 'error' : 'success'}">${c.status}</span>`,
                duration: c.runtime,
            };

        case 'request':
            return {
                icon: 'globe',
                tone: c.response_status >= 500 ? 'error' : c.response_status >= 400 ? 'warning' : '',
                title: `${c.method} ${c.uri}`,
                sub: c.controller_action || '',
                badge: html`<span class="badge ${c.response_status >= 500 ? 'error' : c.response_status >= 400 ? 'warning' : 'success'}">${c.response_status}</span>`,
                duration: c.duration,
            };

        case 'event':
            return { icon: 'radio', tone: '', title: c.name, sub: '', badge: '' };

        case 'alert':
            return {
                icon: 'bell',
                tone: 'warning',
                title: c.title,
                sub: c.message,
                badge: html`<span class="badge ${c.delivered ? 'success' : 'error'}">${c.delivered ? 'delivered' : 'not delivered'}</span>`,
            };

        default:
            return { icon: type.icon, tone: type.tone, title: entry.type, sub: '', badge: '' };
    }
}

export function entryHref(entry) {
    return `#/entries/${entry.type}/${entry.id}`;
}

/**
 * One row of an entries table.
 */
export function entryRow(entry, { showType = false } = {}) {
    const d = describe(entry);

    return html`
        <tr class="clickable" data-href="${entryHref(entry)}" data-sequence="${entry.sequence}">
            <td>
                <div class="row" style="gap:12px">
                    <span class="type-icon ${d.tone}">${icon(d.icon)}</span>
                    <div class="grow" style="min-width:0">
                        <div class="cell-main entry-text">${fmt.truncate(d.title, 140)}</div>
                        <div class="cell-sub entry-text">${showType ? `${TYPES[entry.type]?.single || entry.type} · ` : ''}${fmt.truncate(d.sub, 160)}</div>
                    </div>
                </div>
            </td>
            <td class="hide-sm">${d.badge}</td>
            <td class="num hide-sm muted">${d.duration !== undefined && d.duration !== null ? fmt.duration(d.duration) : ''}</td>
            <td class="num muted" title="${fmt.dateTime(entry.created_at)}">${fmt.ago(entry.timestamp)}</td>
        </tr>`;
}

export function entriesTable(entries, options = {}) {
    return html`
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>${options.heading || 'Entry'}</th><th class="hide-sm">Status</th><th class="num hide-sm">Duration</th><th class="num">Happened</th></tr></thead>
                <tbody>${entries.map((entry) => entryRow(entry, options))}</tbody>
            </table>
        </div>`;
}
