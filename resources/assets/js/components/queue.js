import { html, cls } from '../core/dom.js';
import { icon } from '../core/icons.js';
import * as fmt from '../core/format.js';
import { badge, empty } from './ui.js';

/**
 * The job lists of the queue supervisor, in the order of their tabs.
 */
export const JOB_LISTS = [
    ['pending', 'Pending'],
    ['completed', 'Completed'],
    ['silenced', 'Silenced'],
    ['failed', 'Failed'],
];

const STATUS = {
    pending: ['pending', '', 'clock'],
    reserved: ['running', 'brand', 'refresh'],
    completed: ['completed', 'success', 'check'],
    failed: ['failed', 'error', 'alertCircle'],
};

export function shortName(name) {
    return String(name || '').split('\\').pop();
}

export function jobName(job) {
    return job.payload?.displayName || job.name || 'Job';
}

/**
 * A job timestamp ("1793055991.8965") as unix seconds.
 */
export function time(value) {
    const number = Number(value);

    return Number.isFinite(number) && number > 0 ? number : null;
}

export function statusBadge(status) {
    const [label, tone, iconName] = STATUS[status] || [status || 'unknown', '', 'info'];

    return badge(label, tone, iconName);
}

/**
 * How long a job ran, when it finished.
 */
export function runtime(job) {
    const started = time(job.reserved_at);
    const finished = time(job.completed_at) || time(job.failed_at);

    return started && finished ? fmt.duration((finished - started) * 1000) : '—';
}

export function tagChips(tags) {
    if (!tags?.length) {
        return '';
    }

    return html`<span class="row" style="gap:4px;flex-wrap:wrap">${tags.map((tag) => html`<a class="badge" href="#/queue/monitoring/${encodeURIComponent(tag)}">${icon('tag')}${tag}</a>`)}</span>`;
}

export function listTabs(active) {
    return html`
        <nav class="tabs" style="margin-bottom:20px">
            ${JOB_LISTS.map(([key, label]) => html`<a class="${cls(active === key && 'active')}" href="#/queue/jobs/${key}">${label}</a>`)}
        </nav>`;
}

export function jobHref(job, list) {
    return `#/queue/jobs/${list === 'failed' || job.status === 'failed' ? 'failed' : 'all'}/${encodeURIComponent(job.id)}`;
}

/**
 * A table of queue jobs; failed jobs get a retry button.
 */
export function jobsTable(jobs, { list = 'completed', emptyTitle = 'No jobs' } = {}) {
    if (!jobs.length) {
        return empty({ iconName: list === 'failed' ? 'checkCircle' : 'layers', title: emptyTitle });
    }

    const failed = list === 'failed';

    return html`
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <th>Job</th>
                <th>Queued</th>
                <th class="num">${failed ? 'Failed' : 'Runtime'}</th>
                <th class="num">Status</th>
            </tr></thead>
            <tbody>${jobs.map((job) => html`
                <tr class="clickable" data-href="${jobHref(job, list)}">
                    <td>
                        <div class="row" style="gap:12px">
                            <span class="${cls('type-icon', job.status === 'failed' && 'error')}">${icon(job.status === 'failed' ? 'bug' : 'layers')}</span>
                            <div style="min-width:0">
                                <div class="cell-main" title="${jobName(job)}">${shortName(jobName(job))}</div>
                                <div class="cell-sub">
                                    <span class="mono">${job.queue}</span>
                                    ${job.payload?.attempts > 1 ? html` · attempt ${job.payload.attempts}` : ''}
                                    ${job.payload?.tags?.length ? html` · ${job.payload.tags.slice(0, 3).join(', ')}` : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="muted">${fmt.ago(time(job.payload?.pushedAt))}</td>
                    <td class="num muted">${failed ? fmt.ago(time(job.failed_at)) : runtime(job)}</td>
                    <td class="num">
                        ${failed ? html`<button class="btn xs outline" data-action="retry-job" data-id="${job.id}">${icon('refresh')} Retry</button>` : statusBadge(job.status)}
                    </td>
                </tr>`)}</tbody>
        </table></div>`;
}
