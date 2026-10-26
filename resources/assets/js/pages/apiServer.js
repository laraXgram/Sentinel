import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, empty, badge, props, meter, identity } from '../components/ui.js';
import { SERIES, legend } from '../components/charts.js';

const WINDOWS = [['inf', 'Since start'], ['5sec', '5 seconds'], ['1min', '1 minute'], ['1hour', '1 hour']];

const FIGURES = [
    ['request_count', 'Requests / s', 'rate'],
    ['response_count_ok', 'Successful responses / s', 'rate'],
    ['response_count_error', 'Error responses / s', 'rate'],
    ['update_count', 'Updates / s', 'rate'],
    ['request_bytes', 'Request bytes / s', 'bytes'],
    ['response_bytes', 'Response bytes / s', 'bytes'],
    ['request_file_count', 'Uploaded files / s', 'rate'],
    ['request_files_bytes', 'Uploaded bytes / s', 'bytes'],
    ['total_cpu', 'CPU', 'percent'],
    ['user_cpu', 'User CPU', 'percent'],
    ['system_cpu', 'System CPU', 'percent'],
];

const megabytes = (bytes) => (bytes === null || bytes === undefined ? null : bytes / 1048576);

function figure(value, kind) {
    if (value === null || value === undefined) return '—';
    if (kind === 'bytes') return fmt.bytes(megabytes(value));
    if (kind === 'percent') return `${fmt.number(value)}%`;

    return fmt.number(value);
}

function series(graph, names = null) {
    return Object.entries(graph.series || {}).map(([name, values], index) => ({ name: names?.[name] || name, values, color: SERIES[index % 8] }));
}

export default class ApiServerPage extends Page {
    static live = true;

    title() {
        return 'API Server';
    }

    load() {
        return api.get('metrics/api-server', store.query());
    }

    charts(data) {
        const period = store.get('period');
        const traffic = data.requests.buckets.length ? data.requests : data.updates;

        return {
            traffic: {
                buckets: traffic.buckets,
                series: [
                    { name: 'Requests / s', color: SERIES[0], values: data.requests.series.server || [] },
                    { name: 'Updates / s', color: SERIES[2], values: data.updates.series.server || [] },
                    { name: 'Errors / s', color: SERIES[7], values: data.errors.series.server || [] },
                ],
                format: fmt.number,
                integer: false,
                period,
                height: 220,
            },
            cpu: { buckets: data.cpu.buckets, series: [{ name: 'CPU %', color: SERIES[0], values: data.cpu.series.server || [] }], format: (v) => `${fmt.number(v)}%`, integer: false, period, height: 180 },
            memory: { buckets: data.memory.buckets, series: [{ name: 'Memory', color: SERIES[2], values: data.memory.series.server || [] }], format: fmt.bytes, period, height: 180 },
            active: { buckets: data.active.buckets, series: [{ name: 'Active requests', color: SERIES[4], values: data.active.series.server || [] }], period, height: 180, bars: true },
            bots: { buckets: data.bots.buckets, series: series(data.bots), format: fmt.number, integer: false, period, height: 220 },
        };
    }

    view(data) {
        const live = data.live;

        if (!live.configured) {
            return html`
                ${pageHead({ title: 'API Server', sub: 'The statistics of your local Bot API server.' })}
                <div class="card">${empty({
                    iconName: 'network',
                    title: 'No local Bot API server',
                    text: html`Start <code>telegram-bot-api</code> or the LaraGram API Server with a statistics port — set <code>stat.port</code> in the <code>api_server</code> section of <code>config/bot.php</code> and run <code>php laragram start:apiserver</code> — or point <code>SENTINEL_API_SERVER_STATS_URL</code> at its statistics page.`,
                })}</div>`;
        }

        // Fall back to the last snapshot sentinel:check took while the server is unreachable.
        const stats = live.ok ? live : data.snapshot?.ok ? data.snapshot : null;
        const server = stats?.server || {};
        const bots = stats?.bots || [];

        return html`
            ${pageHead({
                title: 'API Server',
                sub: html`<span class="mono">${data.url}</span>${live.latency !== null ? html` · answered in ${fmt.duration(live.latency)}` : ''}`,
                tools: html`${live.ok ? badge('online', 'success', 'radio') : badge('unreachable', 'error', 'alertCircle')} ${this.periodPicker()}`,
            })}

            ${!live.ok ? html`
                <div class="card" style="margin-bottom:24px">${empty({
                    compact: true,
                    iconName: 'alertTriangle',
                    title: 'The statistics page could not be read',
                    text: html`${live.error}${data.snapshot ? html`<br>Showing the snapshot from ${fmt.ago(data.snapshot.taken_at)}.` : ''}`,
                })}</div>` : ''}

            ${stats ? html`
                <div class="grid cols-4">
                    ${tile({ label: 'Bots', value: server.bot_count, iconName: 'bot', suffix: ` · ${fmt.number(server.active_bot_count)} active` })}
                    ${tile({ label: 'Requests / s (1 min)', value: server.request_count?.['1min'], iconName: 'send', format: fmt.number })}
                    ${tile({ label: 'Updates / s (1 min)', value: server.update_count?.['1min'], iconName: 'inbox', format: fmt.number })}
                    ${tile({ label: 'Uptime', value: server.uptime, iconName: 'clock', format: (v) => fmt.seconds(Math.round(v || 0)) })}
                </div>

                <div class="grid cols-2">
                    ${card({
                        title: 'Right now',
                        body: props([
                            ['Active requests', fmt.number(server.active_requests)],
                            ['Webhook connections', fmt.number(server.active_webhook_connections)],
                            ['Network queries', fmt.number(server.active_network_queries)],
                            ['CPU (1 min)', server.total_cpu?.['1min'] !== undefined && server.total_cpu?.['1min'] !== null ? html`<div class="row" style="gap:8px;min-width:160px">${meter(server.total_cpu['1min'])}<span class="small">${fmt.number(server.total_cpu['1min'])}%</span></div>` : '—'],
                        ]),
                    })}
                    ${card({
                        title: 'Memory',
                        body: props([
                            ['Resident (RSS)', figure(server.rss, 'bytes')],
                            ['Resident peak', figure(server.rss_peak, 'bytes')],
                            ['Virtual', figure(server.vm, 'bytes')],
                            ['Virtual peak', figure(server.vm_peak, 'bytes')],
                            ['Buffers', figure(server.buffer_memory, 'bytes')],
                        ]),
                    })}
                </div>

                ${card({
                    title: 'Traffic',
                    sub: 'Recorded by sentinel:check, per second over the last minute',
                    actions: legend([{ label: 'Requests / s', color: SERIES[0] }, { label: 'Updates / s', color: SERIES[2] }, { label: 'Errors / s', color: SERIES[7] }]),
                    body: html`<div data-chart="traffic"></div>`,
                })}

                <div class="grid cols-3">
                    ${card({ title: 'CPU', body: html`<div data-chart="cpu"></div>` })}
                    ${card({ title: 'Memory', body: html`<div data-chart="memory"></div>` })}
                    ${card({ title: 'Active requests', body: html`<div data-chart="active"></div>` })}
                </div>

                ${card({
                    title: 'By window',
                    sub: 'Averages per second over each window, as the server reports them',
                    flush: true,
                    body: html`
                        <div class="table-wrap"><table class="table compact">
                            <thead><tr><th>Figure</th>${WINDOWS.map(([, label]) => html`<th class="num">${label}</th>`)}</tr></thead>
                            <tbody>${FIGURES.filter(([key]) => server[key] && typeof server[key] === 'object').map(([key, label, kind]) => html`
                                <tr>
                                    <td>${label}</td>
                                    ${WINDOWS.map(([window]) => html`<td class="num">${figure(server[key][window], kind)}</td>`)}
                                </tr>`)}</tbody>
                        </table></div>`,
                })}

                ${card({
                    title: 'Bots',
                    sub: 'The busiest bots on this server',
                    actions: Object.keys(data.bots.series || {}).length ? legend(Object.keys(data.bots.series).map((name, index) => ({ label: name, color: SERIES[index % 8] }))) : '',
                    flush: bots.length > 0,
                    body: bots.length ? html`
                        ${Object.keys(data.bots.series || {}).length ? html`<div style="padding:0 24px 16px" data-chart="bots"></div>` : ''}
                        <div class="table-wrap"><table class="table">
                            <thead><tr><th>Bot</th><th>Delivery</th><th class="num">Pending</th><th class="num">Updates / s</th><th class="num">Requests / s</th><th class="num">Uptime</th></tr></thead>
                            <tbody>${bots.map((bot) => html`
                                <tr ${bot.connection ? html`class="clickable" data-href="#/bots/${encodeURIComponent(bot.connection)}"` : ''}>
                                    <td>${identity(bot.username || String(bot.id), [bot.username ? `@${bot.username}` : null, bot.connection, bot.token].filter(Boolean).join(' · '), bot.id)}</td>
                                    <td class="small">${bot.webhook ? html`${icon('webhook')} <span class="mono">${fmt.truncate(bot.webhook, 48)}</span>` : html`<span class="muted">getUpdates</span>`}</td>
                                    <td class="num">${bot.pending_update_count ? badge(fmt.number(bot.pending_update_count), bot.pending_update_count > 100 ? 'error' : 'warning') : '0'}</td>
                                    <td class="num">${fmt.number(bot['update_count/sec']?.['1min'])}</td>
                                    <td class="num">${fmt.number(bot['request_count/sec']?.['1min'])}</td>
                                    <td class="num muted">${fmt.seconds(Math.round(bot.uptime || 0))}</td>
                                </tr>`)}</tbody>
                        </table></div>` : empty({ compact: true, iconName: 'bot', title: 'No bots have connected yet' }),
                })}` : ''}`;
    }
}
