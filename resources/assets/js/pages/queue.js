import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { navigate } from '../core/router.js';
import { cls } from '../core/dom.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, empty, badge, props, json, code, meter, toast, modal, segmented, skeleton } from '../components/ui.js';
import { SERIES, legend } from '../components/charts.js';
import { JOB_LISTS, shortName, jobName, time, statusBadge, runtime, tagChips, listTabs, jobsTable } from '../components/queue.js';

const PER_PAGE = 50;

/**
 * A trim window in minutes, as "hour" or "7 days".
 */
function window_(minutes) {
    const value = Number(minutes) || 0;

    if (value % 1440 === 0) return value === 1440 ? 'day' : `${value / 1440} days`;
    if (value % 60 === 0) return value === 60 ? 'hour' : `${value / 60} hours`;

    return `${value} minutes`;
}

function statusOf(status) {
    return {
        running: badge('running', 'success', 'radio'),
        paused: badge('paused', 'warning', 'pause'),
        inactive: badge('inactive', 'error', 'slash'),
    }[status] || badge(status);
}

async function retryJob(id) {
    try {
        await api.post(`queue/jobs/${encodeURIComponent(id)}/retry`);
        toast('Job queued again', 'It runs on the next free worker.');
    } catch (error) {
        toast('Could not retry the job', error.message, 'error');
    }
}

/* ------------------------------------------------------------------
 * Overview
 * ------------------------------------------------------------------ */

export class QueuePage extends Page {
    static live = true;

    title() {
        return 'Queues';
    }

    async load() {
        const [stats, workload, masters] = await Promise.all([
            api.get('queue/stats'),
            api.get('queue/workload'),
            api.get('queue/masters'),
        ]);

        return { stats, workload, masters: Object.values(masters || {}) };
    }

    view({ stats, workload, masters }) {
        const wait = Object.entries(stats.wait || {})[0];

        return html`
            ${pageHead({
                title: 'Queues',
                sub: 'Redis queue workers run by sentinel:queue: throughput, waits and the supervisors behind them.',
                tools: statusOf(stats.status),
            })}

            ${stats.status === 'inactive' ? html`
                <div class="card" style="margin-bottom:24px">${empty({
                    iconName: 'layers',
                    title: 'The queue supervisor is not running',
                    text: html`Run <code>php laragram sentinel:queue</code> (with <code>QUEUE_CONNECTION=redis</code>) to start your workers, and keep it running with a process monitor such as Supervisor. Metrics come from the snapshot Sentinel's scheduler takes every five minutes.`,
                })}</div>` : ''}

            <div class="grid cols-4">
                ${tile({ label: 'Jobs per minute', value: stats.jobsPerMinute, iconName: 'zap' })}
                ${tile({ label: `Jobs in the last ${window_(stats.periods?.recentJobs || 60)}`, value: stats.recentJobs, iconName: 'layers', href: '#/queue/jobs/completed' })}
                ${tile({ label: `Failed in the last ${window_(stats.periods?.failedJobs || 10080)}`, value: stats.failedJobs, iconName: 'alertTriangle', tone: stats.failedJobs ? 'error' : '', href: '#/queue/jobs/failed' })}
                ${tile({ label: 'Worker processes', value: stats.processes, iconName: 'cpu' })}
            </div>

            <div class="grid cols-2">
                ${card({
                    title: 'At a glance',
                    body: props([
                        ['Status', statusOf(stats.status)],
                        ['Paused masters', stats.pausedMasters ? fmt.number(stats.pausedMasters) : 'none'],
                        ['Longest wait', wait ? html`<span class="mono">${wait[0]}</span> · ${fmt.seconds(Math.round(wait[1]))}` : '—'],
                        ['Highest throughput', stats.queueWithMaxThroughput ? html`<span class="mono">${stats.queueWithMaxThroughput}</span>` : '—'],
                        ['Longest runtime', stats.queueWithMaxRuntime ? html`<span class="mono">${stats.queueWithMaxRuntime}</span>` : '—'],
                    ]),
                })}
                ${card({
                    title: 'Current workload',
                    sub: 'Jobs waiting per queue and the workers on them',
                    flush: workload.length > 0,
                    body: workload.length ? html`
                        <div class="table-wrap"><table class="table compact">
                            <thead><tr><th>Queue</th><th class="num">Jobs</th><th class="num">Processes</th><th class="num">Wait</th></tr></thead>
                            <tbody>${workload.map((queue) => html`
                                <tr>
                                    <td class="mono">${queue.name.replaceAll(',', ', ')}</td>
                                    <td class="num strong">${fmt.number(queue.length)}</td>
                                    <td class="num">${fmt.number(queue.processes)}</td>
                                    <td class="num">${fmt.seconds(Math.round(queue.wait || 0))}</td>
                                </tr>
                                ${(queue.split_queues || []).map((split) => html`
                                    <tr class="muted">
                                        <td class="mono small" style="padding-left:28px">${split.name}</td>
                                        <td class="num">${fmt.number(split.length)}</td>
                                        <td class="num">—</td>
                                        <td class="num">${fmt.seconds(Math.round(split.wait || 0))}</td>
                                    </tr>`)}`)}</tbody>
                        </table></div>` : empty({ compact: true, iconName: 'inbox', title: 'No queues are being worked' }),
                })}
            </div>

            ${masters.map((master) => card({
                title: html`<span class="row">${icon('server')} ${master.name}</span>`,
                sub: `${master.environment || ''}${master.pid ? ` · pid ${master.pid}` : ''}`,
                actions: statusOf(master.status),
                flush: true,
                body: html`
                    <div class="table-wrap"><table class="table compact">
                        <thead><tr><th>Supervisor</th><th>Queues</th><th class="num">Processes</th><th>Balancing</th><th class="num">Status</th></tr></thead>
                        <tbody>${master.supervisors.map((supervisor) => html`
                            <tr>
                                <td class="strong">${supervisor.name.replace(`${master.name}:`, '')}</td>
                                <td class="mono small">${String(supervisor.options?.queue || '').replaceAll(',', ', ')}</td>
                                <td class="num">${fmt.number(Object.values(supervisor.processes || {}).reduce((sum, count) => sum + Number(count), 0))}</td>
                                <td>${supervisor.options?.balance ? fmt.title(String(supervisor.options.balance)) : 'off'}</td>
                                <td class="num">${statusOf(supervisor.status)}</td>
                            </tr>`)}</tbody>
                    </table></div>`,
            }))}`;
    }
}

/* ------------------------------------------------------------------
 * Job lists
 * ------------------------------------------------------------------ */

export class QueueJobsPage extends Page {
    static live = true;

    setup() {
        this.list = JOB_LISTS.some(([key]) => key === this.params.list) ? this.params.list : 'pending';
        this.jobs = [];

        this.on('click', '[data-action=load-more]', async (e, button) => {
            button.classList.add('loading');

            const result = await this.fetch(this.jobs.length - 1);

            this.jobs.push(...result.jobs);
            this.total = result.total;
            this.paint();
        });

        this.on('click', '[data-action=retry-job]', async (e, button) => {
            button.classList.add('loading');
            await retryJob(button.dataset.id);
            button.classList.remove('loading');
        });

        this.on('submit', 'form[data-action=tag-search]', (e, form) => {
            e.preventDefault();
            navigate(`/queue/jobs/${this.list}`, { tag: form.tag.value.trim() || undefined });
        });
    }

    title() {
        return `${fmt.title(this.list)} jobs`;
    }

    fetch(startingAt = -1) {
        return api.get(`queue/jobs/${this.list}`, { starting_at: startingAt, tag: this.list === 'failed' ? this.query.tag : '' });
    }

    loading() {
        return skeleton({ tiles: 0, blocks: 1 });
    }

    async load() {
        const result = await this.fetch();

        // A live refresh keeps the pages loaded beyond the first.
        this.jobs = this.jobs.length > PER_PAGE ? [...result.jobs, ...this.jobs.slice(result.jobs.length)] : result.jobs;
        this.total = result.total;

        return result;
    }

    view() {
        const subs = {
            pending: 'Jobs waiting for a worker.',
            completed: 'Jobs that finished recently.',
            silenced: 'Completed jobs hidden from the completed list (sentinel.queue.silenced).',
            failed: 'Jobs that ran out of attempts. Retry them once the cause is fixed.',
        };

        return html`
            ${pageHead({ title: `${fmt.title(this.list)} jobs`, sub: subs[this.list], tools: badge(fmt.plural(this.total || 0, 'job')) })}

            ${listTabs(this.list)}

            ${this.list === 'failed' ? html`
                <form class="search" data-action="tag-search" style="max-width:420px;margin-bottom:16px">
                    ${icon('tag')}
                    <input type="search" name="tag" value="${this.query.tag || ''}" placeholder="Filter by tag, e.g. App\\Models\\User:42" autocomplete="off">
                </form>` : ''}

            ${card({
                flush: this.jobs.length > 0,
                body: jobsTable(this.jobs, { list: this.list, emptyTitle: this.list === 'failed' ? 'No failed jobs' : `No ${this.list} jobs` }),
                footer: this.jobs.length < (this.total || 0) ? html`<button class="btn outline sm" data-action="load-more">Load more</button>` : '',
            })}`;
    }
}

/* ------------------------------------------------------------------
 * A single job
 * ------------------------------------------------------------------ */

export class QueueJobPage extends Page {
    setup() {
        this.on('click', '[data-action=retry-job]', async (e, button) => {
            button.classList.add('loading');
            await retryJob(this.params.id);
            button.classList.remove('loading');
            this.render({ soft: true });
        });
    }

    title() {
        return 'Job';
    }

    load() {
        return this.params.list === 'failed'
            ? api.get(`queue/jobs/failed/${encodeURIComponent(this.params.id)}`)
            : api.get(`queue/jobs/${encodeURIComponent(this.params.id)}`);
    }

    view(job) {
        if (!job || !job.id) {
            return html`<div class="card">${empty({ iconName: 'layers', title: 'Job not found', text: 'It may have been trimmed: recent jobs are kept for sentinel.queue.trim minutes.' })}</div>`;
        }

        const payload = job.payload || {};
        const failed = job.status === 'failed';

        return html`
            ${pageHead({
                title: shortName(jobName(job)),
                sub: jobName(job),
                crumbs: [{ label: 'Queues', href: '#/queue' }, { label: failed ? 'Failed jobs' : 'Jobs', href: `#/queue/jobs/${failed ? 'failed' : 'completed'}` }, { label: shortName(jobName(job)) }],
                tools: html`${statusBadge(job.status)}${failed ? html` <button class="btn sm primary" data-action="retry-job">${icon('refresh')} Retry</button>` : ''}`,
            })}

            <div class="grid cols-2">
                ${card({
                    title: 'Job',
                    body: props([
                        ['ID', html`<span class="mono small">${job.id}</span>`],
                        ['Queue', html`<span class="mono">${job.connection} · ${job.queue}</span>`],
                        ['Attempts', payload.attempts !== undefined ? `${payload.attempts}${payload.maxTries ? ` of ${payload.maxTries}` : ''}` : null],
                        ['Timeout', payload.timeout ? `${payload.timeout}s` : null],
                        ['Tags', tagChips(payload.tags)],
                        ['Queued', time(payload.pushedAt) ? fmt.dateTime(time(payload.pushedAt)) : null],
                        ['Started', time(job.reserved_at) ? fmt.dateTime(time(job.reserved_at)) : null],
                        [failed ? 'Failed' : 'Completed', time(job.failed_at || job.completed_at) ? fmt.dateTime(time(job.failed_at || job.completed_at)) : null],
                        ['Runtime', runtime(job)],
                        ['Batch', payload.data?.batchId ? html`<a href="#/queue/batches/${payload.data.batchId}">${payload.data.batchId}</a>` : null],
                    ]),
                })}
                ${card({ title: 'Payload', body: json(payload) })}
            </div>

            ${failed && job.exception ? card({ title: 'Exception', body: code(job.exception) }) : ''}

            ${failed && job.context ? card({ title: 'Context', body: json(typeof job.context === 'string' ? JSON.parse(job.context) : job.context) }) : ''}

            ${failed && job.retried_by?.length ? card({
                title: 'Retries',
                flush: true,
                body: html`
                    <div class="table-wrap"><table class="table compact">
                        <thead><tr><th>Retry</th><th class="num">Retried</th><th class="num">Status</th></tr></thead>
                        <tbody>${job.retried_by.map((retry) => html`
                            <tr class="clickable" data-href="#/queue/jobs/${retry.status === 'failed' ? 'failed' : 'all'}/${encodeURIComponent(retry.id)}">
                                <td class="mono small">${retry.id}</td>
                                <td class="num muted">${fmt.ago(time(retry.retried_at))}</td>
                                <td class="num">${statusBadge(retry.status)}</td>
                            </tr>`)}</tbody>
                    </table></div>`,
            }) : ''}`;
    }
}

/* ------------------------------------------------------------------
 * Batches
 * ------------------------------------------------------------------ */

function progress(batch) {
    return html`<div class="row" style="gap:8px;min-width:140px">${meter(batch.progress, 100, { warn: 101, error: 101 })}<span class="small muted">${batch.progress}%</span></div>`;
}

export class QueueBatchesPage extends Page {
    static live = true;

    setup() {
        this.batches = [];

        this.on('submit', 'form[data-action=batch-search]', (e, form) => {
            e.preventDefault();
            navigate('/queue/batches', { query: form.query.value.trim() || undefined });
        });

        this.on('click', '[data-action=load-more]', async (e, button) => {
            button.classList.add('loading');

            const result = await api.get('queue/batches', { query: this.query.query, before_id: this.batches[this.batches.length - 1]?.id });

            this.batches.push(...result.batches);
            this.more = result.batches.length >= PER_PAGE;
            this.paint();
        });
    }

    title() {
        return 'Batches';
    }

    async load() {
        const result = await api.get('queue/batches', { query: this.query.query });

        this.batches = result.batches;
        this.more = result.batches.length >= PER_PAGE;

        return result;
    }

    view() {
        return html`
            ${pageHead({ title: 'Batches', sub: 'Job batches dispatched with Bus::batch(), their progress and failures.' })}

            <form class="search" data-action="batch-search" style="max-width:420px;margin-bottom:16px">
                ${icon('search')}
                <input type="search" name="query" value="${this.query.query || ''}" placeholder="Search by name or ID" autocomplete="off">
            </form>

            ${card({
                flush: this.batches.length > 0,
                body: this.batches.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Batch</th><th>Progress</th><th class="num">Jobs</th><th class="num">Failed</th><th class="num">Created</th><th class="num">Status</th></tr></thead>
                        <tbody>${this.batches.map((batch) => html`
                            <tr class="clickable" data-href="#/queue/batches/${batch.id}">
                                <td><div class="cell-main">${batch.name || 'Unnamed batch'}</div><div class="cell-sub mono">${batch.id}</div></td>
                                <td>${progress(batch)}</td>
                                <td class="num">${fmt.number(batch.totalJobs)}</td>
                                <td class="${cls('num', batch.failedJobs && 'strong')}" style="${batch.failedJobs ? 'color:var(--error-600)' : ''}">${fmt.number(batch.failedJobs)}</td>
                                <td class="num muted">${fmt.ago(batch.createdAt)}</td>
                                <td class="num">${this.batchStatus(batch)}</td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ iconName: 'layers', title: 'No batches', text: 'Batches need the job_batches table: run php laragram make:queue-batches-table and migrate.' }),
                footer: this.more ? html`<button class="btn outline sm" data-action="load-more">Load more</button>` : '',
            })}`;
    }

    batchStatus(batch) {
        if (batch.cancelledAt) return badge('cancelled', 'warning');
        if (batch.finishedAt) return batch.failedJobs ? badge('finished with failures', 'error') : badge('finished', 'success');

        return badge('running', 'brand', 'refresh');
    }
}

export class QueueBatchPage extends Page {
    static live = true;

    setup() {
        this.on('click', '[data-action=retry-batch]', async (e, button) => {
            button.classList.add('loading');

            try {
                await api.post(`queue/batches/${this.params.id}/retry`);
                toast('Failed jobs queued again');
            } catch (error) {
                toast('Could not retry the batch', error.message, 'error');
            }

            this.render({ soft: true });
        });

        this.on('click', '[data-action=retry-job]', async (e, button) => {
            button.classList.add('loading');
            await retryJob(button.dataset.id);
            button.classList.remove('loading');
        });
    }

    title() {
        return 'Batch';
    }

    load() {
        return api.get(`queue/batches/${this.params.id}`);
    }

    view({ batch, failedJobs }) {
        if (!batch) {
            return html`<div class="card">${empty({ iconName: 'layers', title: 'Batch not found' })}</div>`;
        }

        return html`
            ${pageHead({
                title: batch.name || 'Unnamed batch',
                sub: batch.id,
                crumbs: [{ label: 'Queues', href: '#/queue' }, { label: 'Batches', href: '#/queue/batches' }, { label: batch.name || batch.id }],
                tools: batch.failedJobs ? html`<button class="btn sm primary" data-action="retry-batch">${icon('refresh')} Retry failed jobs</button>` : '',
            })}

            <div class="grid cols-4">
                ${tile({ label: 'Total jobs', value: batch.totalJobs, iconName: 'layers' })}
                ${tile({ label: 'Pending', value: batch.pendingJobs, iconName: 'clock' })}
                ${tile({ label: 'Failed', value: batch.failedJobs, iconName: 'alertTriangle', tone: batch.failedJobs ? 'error' : '' })}
                ${tile({ label: 'Progress', value: batch.progress, iconName: 'activity', format: (value) => `${value}%` })}
            </div>

            ${card({
                title: 'Batch',
                body: props([
                    ['Queue', batch.options?.queue ? html`<span class="mono">${batch.connection || ''} · ${batch.options.queue}</span>` : null],
                    ['Created', fmt.dateTime(batch.createdAt)],
                    ['Finished', batch.finishedAt ? fmt.dateTime(batch.finishedAt) : 'not yet'],
                    ['Cancelled', batch.cancelledAt ? fmt.dateTime(batch.cancelledAt) : null],
                    ['Allows failures', batch.options?.allowFailures ? 'yes' : 'no'],
                ]),
            })}

            ${card({
                title: 'Failed jobs',
                flush: (failedJobs || []).length > 0,
                body: jobsTable((failedJobs || []).map((job) => ({ ...job, payload: typeof job.payload === 'string' ? JSON.parse(job.payload) : job.payload })), { list: 'failed', emptyTitle: 'No failed jobs in this batch' }),
            })}`;
    }
}

/* ------------------------------------------------------------------
 * Metrics
 * ------------------------------------------------------------------ */

export class QueueMetricsPage extends Page {
    title() {
        return 'Queue metrics';
    }

    get kind() {
        return this.params.kind === 'queues' ? 'queues' : 'jobs';
    }

    load() {
        return api.get(`queue/metrics/${this.kind}`);
    }

    view(names) {
        const list = Object.values(names || {});

        return html`
            ${pageHead({ title: 'Queue metrics', sub: 'Throughput and runtime per job and per queue, from the snapshots sentinel:queue:snapshot takes.' })}

            <nav class="tabs" style="margin-bottom:20px">
                <a class="${cls(this.kind === 'jobs' && 'active')}" href="#/queue/metrics/jobs">Jobs</a>
                <a class="${cls(this.kind === 'queues' && 'active')}" href="#/queue/metrics/queues">Queues</a>
            </nav>

            ${card({
                flush: list.length > 0,
                body: list.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>${this.kind === 'jobs' ? 'Job' : 'Queue'}</th></tr></thead>
                        <tbody>${list.map((name) => html`
                            <tr class="clickable" data-href="#/queue/metrics/${this.kind}/${encodeURIComponent(name)}">
                                <td><div class="cell-main">${this.kind === 'jobs' ? shortName(name) : name}</div>${this.kind === 'jobs' ? html`<div class="cell-sub mono">${name}</div>` : ''}</td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ iconName: 'gauge', title: 'No metrics yet', text: html`Sentinel's scheduler takes a snapshot every five minutes (<code>php laragram sentinel:queue:snapshot</code>); make sure your scheduler runs.` }),
            })}`;
    }
}

export class QueueMetricPage extends Page {
    title() {
        return 'Queue metric';
    }

    load() {
        return api.get(`queue/metrics/${this.params.kind}/${encodeURIComponent(this.params.id)}`);
    }

    charts(snapshots) {
        const list = Object.values(snapshots || {});
        const buckets = list.map((snapshot) => Number(snapshot.time));

        return {
            throughput: { buckets, series: [{ name: 'Jobs', color: SERIES[0], values: list.map((snapshot) => snapshot.throughput) }], period: 60, height: 220, bars: true },
            runtime: { buckets, series: [{ name: 'Runtime', color: SERIES[2], values: list.map((snapshot) => snapshot.runtime * 1000) }], period: 60, height: 220, format: fmt.duration, bars: list.length < 3 },
        };
    }

    view(snapshots) {
        const list = Object.values(snapshots || {});
        const jobs = this.params.kind === 'jobs';
        const total = list.reduce((sum, snapshot) => sum + Number(snapshot.throughput), 0);
        const average = list.length ? list.reduce((sum, snapshot) => sum + Number(snapshot.runtime), 0) / list.length : 0;

        return html`
            ${pageHead({
                title: jobs ? shortName(this.params.id) : this.params.id,
                sub: jobs ? this.params.id : 'Queue',
                crumbs: [{ label: 'Queues', href: '#/queue' }, { label: 'Metrics', href: `#/queue/metrics/${this.params.kind}` }, { label: jobs ? shortName(this.params.id) : this.params.id }],
            })}

            <div class="grid cols-2">
                ${tile({ label: 'Jobs in the snapshots', value: total, iconName: 'layers' })}
                ${tile({ label: 'Average runtime', value: average * 1000, iconName: 'timer', format: fmt.duration })}
            </div>

            ${card({ title: 'Throughput', sub: 'Jobs processed per snapshot', actions: legend([{ label: 'Jobs', color: SERIES[0] }]), body: html`<div data-chart="throughput"></div>` })}
            ${card({ title: 'Runtime', sub: 'Average runtime per snapshot', actions: legend([{ label: 'Runtime', color: SERIES[2] }]), body: html`<div data-chart="runtime"></div>` })}`;
    }
}

/* ------------------------------------------------------------------
 * Monitored tags
 * ------------------------------------------------------------------ */

export class QueueMonitoringPage extends Page {
    static live = true;

    setup() {
        this.on('click', '[data-action=monitor-tag]', () => modal({
            title: 'Monitor a tag',
            text: 'Every job with this tag is kept for sentinel.queue.trim.monitored minutes, completed or failed.',
            body: html`<div class="field"><label>Tag</label><input class="input" name="tag" placeholder="App\\Models\\User:42" required></div>`,
            confirm: 'Monitor',
            onConfirm: async (values) => {
                if (!values.tag?.trim()) return false;

                await api.post('queue/monitoring', { tag: values.tag.trim() });
                toast('Monitoring tag', values.tag.trim());
                setTimeout(() => this.render({ soft: true }), 600);

                return true;
            },
        }));

        this.on('click', '[data-action=stop-monitoring]', (e, button) => modal({
            title: 'Stop monitoring?',
            text: `The jobs recorded for "${button.dataset.tag}" are deleted.`,
            confirm: 'Stop monitoring',
            tone: 'danger',
            onConfirm: async () => {
                await api.delete(`queue/monitoring/${encodeURIComponent(button.dataset.tag)}`);
                setTimeout(() => this.render({ soft: true }), 600);

                return true;
            },
        }));
    }

    title() {
        return 'Monitored tags';
    }

    load() {
        return api.get('queue/monitoring');
    }

    view(tags) {
        const list = Object.values(tags || {});

        return html`
            ${pageHead({
                title: 'Monitored tags',
                sub: 'Follow every job of a user, a chat or a model. Jobs are tagged with the models they carry (App\\Models\\User:42) and with their tags() method.',
                tools: html`<button class="btn sm primary" data-action="monitor-tag">${icon('tag')} Monitor tag</button>`,
            })}

            ${card({
                flush: list.length > 0,
                body: list.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Tag</th><th class="num">Jobs</th><th></th></tr></thead>
                        <tbody>${list.map((tag) => html`
                            <tr class="clickable" data-href="#/queue/monitoring/${encodeURIComponent(tag.tag)}">
                                <td class="mono">${tag.tag}</td>
                                <td class="num strong">${fmt.number(tag.count)}</td>
                                <td class="num"><button class="btn xs ghost" data-action="stop-monitoring" data-tag="${tag.tag}">${icon('trash')}</button></td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ iconName: 'tag', title: 'No monitored tags', text: 'Monitor a tag to keep every job that carries it.' }),
            })}`;
    }
}

export class QueueTagPage extends Page {
    static live = true;

    setup() {
        this.failed = this.query.list === 'failed';
        this.jobs = [];

        this.on('click', '[data-action=tag-list]', (e, button) => navigate(`/queue/monitoring/${encodeURIComponent(this.params.tag)}`, { list: button.dataset.value === 'failed' ? 'failed' : undefined }));

        this.on('click', '[data-action=load-more]', async (e, button) => {
            button.classList.add('loading');

            const result = await this.fetch(this.jobs.length);

            this.jobs.push(...result.jobs);
            this.total = result.total;
            this.paint();
        });

        this.on('click', '[data-action=retry-job]', async (e, button) => {
            button.classList.add('loading');
            await retryJob(button.dataset.id);
            button.classList.remove('loading');
        });
    }

    title() {
        return this.params.tag;
    }

    fetch(offset = 0) {
        return this.failed
            ? api.get('queue/jobs/failed', { tag: this.params.tag, starting_at: offset - 1 })
            : api.get('queue/monitoring/jobs', { tag: this.params.tag, starting_at: offset, limit: PER_PAGE });
    }

    async load() {
        const result = await this.fetch();

        this.jobs = result.jobs;
        this.total = result.total;

        return result;
    }

    view() {
        return html`
            ${pageHead({
                title: this.params.tag,
                sub: fmt.plural(this.total || 0, 'job'),
                crumbs: [{ label: 'Queues', href: '#/queue' }, { label: 'Monitored tags', href: '#/queue/monitoring' }, { label: this.params.tag }],
                tools: segmented([['all', 'All jobs'], ['failed', 'Failed']], this.failed ? 'failed' : 'all', 'tag-list'),
            })}

            ${card({
                flush: this.jobs.length > 0,
                body: jobsTable(this.jobs, { list: this.failed ? 'failed' : 'completed', emptyTitle: this.failed ? 'No failed jobs with this tag' : 'No jobs with this tag yet' }),
                footer: this.jobs.length < (this.total || 0) ? html`<button class="btn outline sm" data-action="load-more">Load more</button>` : '',
            })}`;
    }
}
