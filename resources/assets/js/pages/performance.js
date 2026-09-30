import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, empty, barList, code, sql } from '../components/ui.js';
import { legend, SERIES } from '../components/charts.js';

const LEVEL_COLORS = { emergency: 'var(--error-600)', alert: 'var(--error-600)', critical: 'var(--error-600)', error: 'var(--error-500)', warning: 'var(--warning-500)', notice: 'var(--info-500)', info: 'var(--info-500)', debug: 'var(--gray-400)' };

export default class PerformancePage extends Page {
    static live = true;

    title() {
        return 'Performance';
    }

    load() {
        return api.get('metrics/performance', store.query());
    }

    charts(data) {
        return {
            jobs: {
                buckets: data.jobs_graph.buckets,
                series: [
                    { name: 'Processed', color: SERIES[2], values: data.jobs_graph.series.job || [] },
                    { name: 'Failed', color: SERIES[7], values: data.jobs_graph.series.job_failed || [] },
                ],
                period: store.get('period'),
                height: 220,
            },
        };
    }

    view(data) {
        const cacheTotal = data.cache.hit + data.cache.missed;
        const queries = data.queries.reduce((sum, row) => sum + row.count, 0);

        return html`
            ${pageHead({ title: 'Performance', sub: 'Slow updates, slow queries, jobs, cache and web requests.', tools: this.periodPicker() })}

            <div class="grid cols-4">
                ${tile({ label: 'Database queries', value: queries, iconName: 'database', tone: 'brand' })}
                ${tile({ label: 'Slow queries', value: data.slow_queries.reduce((sum, row) => sum + row.count, 0), iconName: 'timer', tone: 'warning', href: '#/entries/query?tag=slow' })}
                ${tile({ label: 'Cache hit ratio', value: cacheTotal ? (data.cache.hit / cacheTotal) * 100 : null, format: (v) => (v === null ? '—' : fmt.percent(v)), iconName: 'zap', tone: 'success' })}
                ${tile({ label: 'Failed jobs', value: Object.values(data.failed_jobs).reduce((sum, count) => sum + count, 0), iconName: 'layers', tone: 'error', href: '#/entries/job?tag=failed' })}
            </div>

            <div class="grid cols-2">
                ${card({
                    title: 'Slow updates',
                    sub: 'Update types that took longer than the configured threshold',
                    body: barList(data.slow_updates.map((row) => ({ label: row.key, value: row.max, extra: `${fmt.compact(row.count)}×` })), { format: fmt.duration, color: 'var(--warning-500)', empty: 'No slow updates', href: () => '#/entries/update?tag=slow' }),
                })}
                ${card({
                    title: 'Log levels',
                    body: barList(Object.entries(data.logs).map(([level, count]) => ({ label: level, value: count, color: LEVEL_COLORS[level] })), { empty: 'Nothing was logged', href: (row) => `#/entries/log?tag=level:${row.label}` }),
                })}
            </div>

            ${card({
                title: 'Slow queries',
                flush: data.slow_queries.length > 0,
                body: data.slow_queries.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Query</th><th class="num">Count</th><th class="num">Slowest</th></tr></thead>
                        <tbody>${data.slow_queries.map((row) => html`
                            <tr>
                                <td style="max-width:720px">${code(sql(row.sql), true)}<div class="cell-sub mono mt-2">${row.location || ''}</div></td>
                                <td class="num strong">${fmt.number(row.count)}</td>
                                <td class="num">${fmt.duration(row.max)}</td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ compact: true, iconName: 'database', title: 'No slow queries', text: 'Queries slower than the configured threshold show up here.' }),
            })}

            <div class="grid cols-12">
                <div class="span-7">${card({ title: 'Jobs', actions: legend([{ label: 'Processed', color: SERIES[2] }, { label: 'Failed', color: SERIES[7] }]), body: html`<div data-chart="jobs"></div>` })}</div>
                <div class="span-5">
                    ${card({
                        title: 'Busiest jobs',
                        body: barList(data.jobs.map((row) => ({ label: row.key.split('\\').pop(), title: row.key, value: row.count, extra: `${fmt.duration(row.avg)} avg${data.failed_jobs[row.key] ? ` · ${data.failed_jobs[row.key]} failed` : ''}` })), { empty: 'No jobs processed' }),
                    })}
                </div>
            </div>

            <div class="grid cols-2">
                ${card({
                    title: 'Slowest web requests',
                    flush: data.requests.length > 0,
                    body: data.requests.length ? html`
                        <div class="table-wrap"><table class="table compact">
                            <thead><tr><th>Route</th><th class="num">Count</th><th class="num">Avg</th><th class="num">Max</th></tr></thead>
                            <tbody>${data.requests.map((row) => html`<tr><td class="mono small">${row.key}</td><td class="num">${fmt.number(row.count)}</td><td class="num">${fmt.duration(row.avg)}</td><td class="num strong">${fmt.duration(row.max)}</td></tr>`)}</tbody>
                        </table></div>` : empty({ compact: true, iconName: 'globe', title: 'No web requests' }),
                })}
                ${card({
                    title: 'Console & schedule',
                    body: barList([...data.commands.map((row) => ({ label: row.key, value: row.count })), ...data.scheduled.map((row) => ({ label: `⏱ ${row.key}`, value: row.count }))], { empty: 'No commands ran' }),
                })}
            </div>`;
    }
}
