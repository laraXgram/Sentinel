import { Page, html } from '../core/page.js';
import { raw } from '../core/dom.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, barList, empty, gauge, identity } from '../components/ui.js';
import { sparkline, legend, foldSeries, updateColor, SERIES } from '../components/charts.js';
import { updateMeta, personName, personSub } from '../components/telegram.js';

export default class OverviewPage extends Page {
    static live = true;

    title() {
        return 'Dashboard';
    }

    load() {
        return api.get('overview', store.query());
    }

    traffic(data) {
        const entries = Object.entries(data.traffic.series).map(([name, values]) => ({
            name,
            values,
            total: values.reduce((sum, value) => sum + (Number(value) || 0), 0),
        }));

        return foldSeries(entries, 5, updateColor).map((series) => ({ ...series, label: series.name === 'Other' ? 'Other' : updateMeta(series.name).label }));
    }

    charts(data) {
        const period = store.get('period');
        const activity = data.activity.series;
        const latency = data.latency.series;

        return {
            traffic: {
                buckets: data.traffic.buckets.length ? data.traffic.buckets : data.activity.buckets,
                series: this.traffic(data).map((s) => ({ name: s.label, color: s.color, values: s.values })),
                bars: true,
                stacked: true,
                period,
                height: 280,
            },
            activity: {
                buckets: data.activity.buckets,
                series: [
                    { name: 'Updates', color: SERIES[0], values: activity.update || [] },
                    { name: 'API calls', color: SERIES[2], values: activity.api_call || [] },
                    { name: 'API errors', color: SERIES[7], values: activity.api_error || [] },
                    { name: 'Exceptions', color: SERIES[6], values: activity.exception || [] },
                ],
                period,
                height: 260,
            },
            latency: {
                buckets: data.latency.buckets,
                series: [
                    { name: 'Update handling', color: SERIES[0], values: latency.update || [], area: true },
                    { name: 'Telegram API call', color: SERIES[1], values: latency.api_call || [] },
                ],
                format: fmt.duration,
                period,
                height: 240,
            },
            types: {
                type: 'donut',
                items: data.update_types.map((row) => ({ label: updateMeta(row.key).label, value: row.count, color: updateColor(row.key) })),
                label: 'updates',
                size: 190,
            },
        };
    }

    view(data) {
        const t = data.tiles;
        const period = store.get('period');
        const activity = data.activity.series;
        const traffic = this.traffic(data);

        return html`
            ${pageHead({
                title: 'Dashboard',
                sub: `How your bot has been doing over the ${fmt.periodName(period)}.`,
                tools: this.periodPicker(),
            })}

            <div class="grid cols-4">
                ${tile({ label: 'Updates received', value: t.updates.value, previous: t.updates.previous, iconName: 'inbox', tone: 'brand', spark: sparkline(activity.update, 'var(--series-1)'), href: '#/entries/update' })}
                ${tile({ label: 'Active users', value: t.users.value, previous: t.users.previous, iconName: 'users', tone: 'info', href: '#/audience/user' })}
                ${tile({ label: 'Telegram API calls', value: t.api_calls.value, previous: t.api_calls.previous, iconName: 'send', tone: 'success', spark: sparkline(activity.api_call, 'var(--series-3)'), href: '#/api' })}
                ${tile({ label: 'Avg. response time', value: t.response_time.value, previous: t.response_time.previous, format: fmt.duration, iconName: 'timer', tone: 'warning', goodWhenUp: false, spark: sparkline(data.latency.series.update, 'var(--series-2)') })}
            </div>

            <div class="grid cols-12">
                <div class="span-8">
                    ${card({
                        title: 'Update traffic',
                        sub: 'Incoming updates by type',
                        actions: legend(traffic.map((s) => ({ label: s.label, color: s.color }))),
                        body: html`<div data-chart="traffic"></div>`,
                    })}
                </div>
                <div class="span-4">${this.webhooks(data.webhooks)}</div>
            </div>

            <div class="grid cols-4">
                ${tile({ label: 'New users', value: t.new_users.value, previous: t.new_users.previous, iconName: 'userPlus', tone: 'brand' })}
                ${tile({ label: 'Failed API calls', value: t.api_errors.value, previous: t.api_errors.previous, iconName: 'alertTriangle', tone: 'error', goodWhenUp: false, href: '#/entries/api_call?tag=failed' })}
                ${tile({ label: 'Exceptions', value: t.exceptions.value, previous: t.exceptions.previous, iconName: 'bug', tone: 'error', goodWhenUp: false, href: '#/exceptions' })}
                ${tile({ label: 'Unhandled updates', value: t.unhandled.value, previous: t.unhandled.previous, iconName: 'route', tone: 'warning', goodWhenUp: false, href: '#/entries/update?tag=unhandled' })}
            </div>

            <div class="grid cols-12">
                <div class="span-4">
                    ${card({
                        title: 'Update types',
                        sub: `${fmt.plural(t.updates.value, 'update')} in the ${fmt.periodName(period)}`,
                        body: data.update_types.length ? html`
                            <div class="row" style="justify-content:center"><div data-chart="types"></div></div>
                            <div class="list mt-4">
                                ${data.update_types.slice(0, 6).map((row) => html`
                                    <div class="list-item" style="padding:9px 0">
                                        <span class="swatch" style="background:${raw(updateColor(row.key))};border-radius:50%"></span>
                                        <span class="grow">${updateMeta(row.key).label}</span>
                                        <span class="muted small">${fmt.duration(row.avg)} avg</span>
                                        <span class="strong tabular" style="min-width:48px;text-align:right">${fmt.compact(row.count)}</span>
                                    </div>`)}
                            </div>` : empty({ compact: true, title: 'No updates yet', text: 'Updates show up here as soon as Telegram delivers them.' }),
                    })}
                </div>
                <div class="span-8">
                    ${card({
                        title: 'Activity',
                        sub: 'Updates, API calls and failures on one scale',
                        actions: legend([
                            { label: 'Updates', color: SERIES[0] },
                            { label: 'API calls', color: SERIES[2] },
                            { label: 'API errors', color: SERIES[7] },
                            { label: 'Exceptions', color: SERIES[6] },
                        ]),
                        body: html`<div data-chart="activity"></div>`,
                    })}
                </div>
            </div>

            <div class="grid cols-12">
                <div class="span-6">
                    ${card({
                        title: 'Response time',
                        sub: `Handling ${fmt.duration(t.response_time.value)} · Telegram API ${fmt.duration(t.api_time.value)} on average`,
                        actions: legend([{ label: 'Update handling', color: SERIES[0] }, { label: 'Telegram API call', color: SERIES[1] }]),
                        body: html`<div data-chart="latency"></div>`,
                    })}
                </div>
                <div class="span-6">
                    ${card({
                        title: 'Busiest API methods',
                        actions: html`<a class="btn xs outline" href="#/api">View all</a>`,
                        flush: !!data.api_methods.length,
                        body: data.api_methods.length ? html`
                            <div class="table-wrap"><table class="table compact">
                                <thead><tr><th>Method</th><th class="num">Calls</th><th class="num">Avg</th><th class="num">Max</th></tr></thead>
                                <tbody>${data.api_methods.map((row) => html`
                                    <tr class="clickable" data-href="#/entries/api_call?tag=method:${row.key}">
                                        <td><span class="cell-main mono">${row.key}</span></td>
                                        <td class="num strong">${fmt.number(row.count)}</td>
                                        <td class="num muted">${fmt.duration(row.avg)}</td>
                                        <td class="num muted">${fmt.duration(row.max)}</td>
                                    </tr>`)}</tbody>
                            </table></div>` : empty({ compact: true, iconName: 'send', title: 'No API calls yet' }),
                    })}
                </div>
            </div>

            <div class="grid cols-12">
                <div class="span-4">
                    ${card({
                        title: 'Top commands',
                        body: barList(data.commands.map((row) => ({ label: row.key, value: row.count, extra: fmt.duration(row.avg) })), {
                            empty: 'No commands yet',
                            href: (row) => `#/entries/update?tag=command:${row.label}`,
                        }),
                    })}
                </div>
                <div class="span-4">
                    ${card({
                        title: 'Slowest handlers',
                        sub: 'Average time per matched listen',
                        body: barList(data.slow_listens.map((row) => ({ label: row.key, value: row.avg, extra: `${fmt.compact(row.count)}×` })), {
                            format: fmt.duration,
                            color: 'var(--warning-500)',
                            empty: 'No handlers ran yet',
                        }),
                    })}
                </div>
                <div class="span-4">${this.problems(data)}</div>
            </div>

            <div class="grid cols-2">
                ${this.people('Most active users', data.top_users, 'user')}
                ${this.people('Most active chats', data.top_chats, 'chat')}
            </div>`;
    }

    webhooks(snapshots) {
        const connections = store.get('connection') === 'all' ? snapshots : snapshots.filter((s) => s.key === store.get('connection'));

        if (!connections.length) {
            return card({
                title: 'Webhook health',
                className: 'accent',
                body: empty({
                    iconName: 'webhook',
                    title: 'No snapshot yet',
                    text: 'Open Bots & Webhooks to check now, or run `php laragram sentinel:check` to keep an eye on it.',
                    action: html`<a class="btn primary sm" href="#/bots">${icon('webhook')} Inspect webhooks</a>`,
                }),
            });
        }

        return card({
            title: 'Webhook health',
            sub: `Checked ${fmt.ago(Math.max(...connections.map((c) => c.timestamp)))}`,
            className: 'accent',
            actions: html`<a class="btn xs outline" href="#/bots">Inspect</a>`,
            body: html`${connections.map((snapshot, index) => {
                const webhook = snapshot.value || {};
                const health = webhook.health || {};
                const info = webhook.info || {};

                return html`
                    ${index ? html`<div class="divider"></div>` : ''}
                    <a class="row" href="#/bots/${snapshot.key}" style="gap:18px">
                        ${gauge(health.score, health.status)}
                        <div class="grow">
                            <div class="strong">${snapshot.key}</div>
                            <div class="mt-2">${this.healthBadge(health.status)}</div>
                            <div class="small muted mt-2">${fmt.number(info.pending_update_count ?? 0)} pending · ${info.max_connections ?? '—'} connections</div>
                            ${health.issues?.[0] ? html`<div class="small mt-2" style="color:var(--${health.status === 'error' ? 'error' : 'warning'}-600)">${health.issues[0].title}</div>` : ''}
                        </div>
                    </a>`;
            })}`,
        });
    }

    healthBadge(status) {
        const map = {
            healthy: ['success', 'Healthy', 'checkCircle'],
            warning: ['warning', 'Needs attention', 'alertTriangle'],
            error: ['error', 'Failing', 'alertCircle'],
            inactive: ['', 'No webhook', 'pause'],
        };
        const [tone, label, iconName] = map[status] || ['', 'Unknown', 'info'];

        return html`<span class="badge ${tone}">${icon(iconName)}${label}</span>`;
    }

    problems(data) {
        const rows = [
            ...data.exceptions.map((row) => ({ tone: 'error', icon: 'bug', title: row.class?.split('\\').pop(), sub: row.location, count: row.count, href: '#/exceptions' })),
            ...data.api_errors.map((row) => ({ tone: 'warning', icon: 'send', title: `${row.method} · ${row.code}`, sub: row.description, count: row.count, href: `#/entries/api_call?tag=method:${row.method},failed` })),
            ...data.unhandled.map((row) => ({ tone: '', icon: 'route', title: `Unhandled ${row.key}`, sub: 'No listen matched these updates', count: row.count, href: '#/entries/update?tag=unhandled' })),
        ].sort((a, b) => b.count - a.count).slice(0, 7);

        return card({
            title: 'Needs attention',
            body: rows.length ? html`
                <div class="list">
                    ${rows.map((row) => html`
                        <a class="list-item" href="${row.href}">
                            <span class="type-icon ${row.tone}">${icon(row.icon)}</span>
                            <div class="grow" style="min-width:0">
                                <div class="list-title truncate">${row.title}</div>
                                <div class="list-sub truncate">${row.sub}</div>
                            </div>
                            <span class="badge ${row.tone}">${fmt.compact(row.count)}</span>
                        </a>`)}
                </div>` : empty({ compact: true, iconName: 'checkCircle', title: 'All clear', text: 'No exceptions, failed calls or unhandled updates.' }),
        });
    }

    people(title, rows, kind) {
        return card({
            title,
            actions: html`<a class="btn xs outline" href="#/audience/${kind}">View all</a>`,
            body: rows.length ? html`
                <div class="list">
                    ${rows.map((row) => {
                        const meta = row.meta || { id: row.key };

                        return html`
                            <a class="list-item" href="#/entries/update?tag=${kind}:${row.key}">
                                ${identity(personName(meta), personSub(meta) || row.key, row.key, 'sm')}
                                <span class="grow"></span>
                                ${meta.type && kind === 'chat' ? html`<span class="badge">${meta.type}</span>` : ''}
                                <span class="strong tabular">${fmt.compact(row.count)}</span>
                            </a>`;
                    })}
                </div>` : empty({ compact: true, iconName: kind === 'chat' ? 'chats' : 'users', title: `No ${kind}s yet` }),
        });
    }
}
