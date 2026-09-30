import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, pageHead, empty, meter, props, badge } from '../components/ui.js';
import { SERIES, legend } from '../components/charts.js';

export default class ServersPage extends Page {
    static live = true;

    title() {
        return 'Servers & Network';
    }

    load() {
        return api.get('metrics/servers', store.query());
    }

    charts(data) {
        const period = store.get('period');
        const charts = {
            latency: { buckets: data.latency.buckets, series: Object.entries(data.latency.series).map(([name, values], index) => ({ name, values, color: SERIES[index % 8] })), format: fmt.duration, period, height: 220 },
            pending: { buckets: data.pending.buckets, series: Object.entries(data.pending.series).map(([name, values], index) => ({ name, values, color: SERIES[index % 8] })), period, height: 220 },
        };

        data.servers.forEach((server, index) => {
            charts[`server-${index}`] = {
                buckets: server.cpu_graph.buckets,
                series: [
                    { name: 'CPU %', color: SERIES[0], values: server.cpu_graph.series.cpu || [] },
                    { name: 'Memory %', color: SERIES[2], values: (server.memory_graph.series.memory || []).map((v) => (v === null || !server.memory_total ? null : Math.round((v / server.memory_total) * 100))) },
                ],
                format: (v) => `${Math.round(v)}%`,
                period,
                height: 160,
            };
        });

        return charts;
    }

    view(data) {
        const stale = (server) => Date.now() / 1000 - server.updated_at > 60;

        return html`
            ${pageHead({ title: 'Servers & Network', sub: 'Machines running your bot, the Telegram API round trip and the proxy pool.', tools: this.periodPicker() })}

            ${data.servers.length ? html`<div class="grid cols-2">${data.servers.map((server, index) => card({
                title: html`<span class="row">${icon('server')} ${server.name}</span>`,
                sub: `PHP ${server.php} · ${server.cores} cores · up ${fmt.seconds(server.uptime || 0)}`,
                actions: stale(server) ? badge(`last seen ${fmt.ago(server.updated_at)}`, 'warning') : badge('online', 'success', 'radio'),
                body: html`
                    <div class="grid cols-3" style="gap:16px">
                        <div><div class="row between small"><span class="muted">CPU</span><b>${server.cpu}%</b></div><div class="mt-2">${meter(server.cpu)}</div></div>
                        <div><div class="row between small"><span class="muted">Memory</span><b>${fmt.bytes(server.memory_used)}</b></div><div class="mt-2">${meter(server.memory_used, server.memory_total)}</div></div>
                        ${server.storage.map((disk) => html`<div><div class="row between small"><span class="muted">Disk ${disk.directory}</span><b>${fmt.bytes(disk.used)}</b></div><div class="mt-2">${meter(disk.used, disk.total, { warn: 80, error: 92 })}</div></div>`)}
                    </div>
                    <div class="mt-4">${legend([{ label: 'CPU', color: SERIES[0] }, { label: 'Memory', color: SERIES[2] }])}</div>
                    <div class="mt-2" data-chart="server-${index}"></div>`,
            }))}</div>` : html`<div class="card">${empty({
                iconName: 'server',
                title: 'No servers reporting',
                text: html`Run <code>php laragram sentinel:check</code> on each server. It records CPU, memory and disk, and snapshots every webhook.`,
            })}</div>`}

            <div class="grid cols-2">
                ${card({ title: 'Telegram API latency', sub: 'Measured by sentinel:check per bot', actions: legend(Object.keys(data.latency.series).map((name, index) => ({ label: name, color: SERIES[index % 8] }))), body: data.latency.buckets.length ? html`<div data-chart="latency"></div>` : empty({ compact: true, iconName: 'clock', title: 'No measurements yet' }) })}
                ${card({ title: 'Pending updates', sub: 'Updates Telegram is holding back', actions: legend(Object.keys(data.pending.series).map((name, index) => ({ label: name, color: SERIES[index % 8] }))), body: data.pending.buckets.length ? html`<div data-chart="pending"></div>` : empty({ compact: true, iconName: 'inbox', title: 'No measurements yet' }) })}
            </div>

            <div class="grid cols-2">
                ${this.proxy(data)}
                ${this.antiFlood(data.anti_flood)}
            </div>`;
    }

    proxy(data) {
        const stats = data.proxy;

        if (!stats) {
            return card({ title: 'Proxy pool', body: empty({ compact: true, iconName: 'network', title: 'Proxy pool is off', text: 'Enable bot.proxy to route Bot API calls through a pool of proxies with automatic fail-over.' }) });
        }

        const pings = data.proxy_snapshot?.value?.pings || {};

        return card({
            title: 'Proxy pool',
            sub: `${stats.up} up · ${stats.down} down · active: ${stats.active || 'none'}`,
            flush: true,
            body: html`
                <div class="table-wrap"><table class="table compact">
                    <thead><tr><th>Proxy</th><th>Status</th><th class="num">Ping</th></tr></thead>
                    <tbody>${(stats.proxies || []).map((proxy) => {
                        const id = proxy.id || proxy.name;
                        const ping = pings[id];

                        return html`<tr><td class="mono small">${id}${id === stats.active ? html` ${badge('active', 'brand')}` : ''}</td><td>${proxy.down ? badge('down', 'error') : badge('up', 'success')}</td><td class="num">${typeof ping === 'number' ? fmt.duration(ping) : '—'}</td></tr>`;
                    })}</tbody>
                </table></div>`,
        });
    }

    antiFlood(config) {
        if (!config) {
            return '';
        }

        return card({
            title: 'Anti-flood',
            actions: config.enabled ? badge('enabled', 'success') : badge('disabled'),
            body: props([
                ['Global', `${config.global?.rate} calls / ${config.global?.per}s (burst ${config.global?.burst})`],
                ['Private chats', `${config.chat?.private?.rate} / ${config.chat?.private?.per}s`],
                ['Groups', `${config.chat?.group?.rate} / ${config.chat?.group?.per}s`],
                ['Store', config.store],
                ['Reactive cooldown', config.reactive?.enabled ? 'on' : 'off'],
                ['Custom scopes', Object.keys(config.custom || {}).join(', ') || '—'],
            ]),
        });
    }
}
