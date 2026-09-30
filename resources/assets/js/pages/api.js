import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, empty } from '../components/ui.js';
import { legend, SERIES } from '../components/charts.js';
import { entriesTable } from '../components/entries.js';

export default class ApiPage extends Page {
    static live = true;

    title() {
        return 'API Calls';
    }

    async load() {
        const connection = store.get('connection');
        const [metrics, recent] = await Promise.all([
            api.get('metrics/api', store.query()),
            api.get('entries/type/api_call', { take: 12, connection: connection === 'all' ? '' : connection }),
        ]);

        return { ...metrics, recent: recent.entries };
    }

    charts(data) {
        const period = store.get('period');

        return {
            calls: {
                buckets: data.calls.buckets,
                series: [
                    { name: 'Calls', color: SERIES[2], values: data.calls.series.api_call || [], area: true },
                    { name: 'Failed', color: SERIES[7], values: data.calls.series.api_error || [] },
                ],
                period,
                height: 260,
            },
            duration: {
                buckets: data.duration.buckets,
                series: [{ name: 'Average duration', color: SERIES[1], values: data.duration.series.api_call || [] }],
                format: fmt.duration,
                period,
                height: 260,
            },
        };
    }

    view(data) {
        const t = data.totals;
        const errorRate = t.api_call ? (t.api_error / t.api_call) * 100 : 0;

        return html`
            ${pageHead({
                title: 'Telegram API Calls',
                sub: 'Every request your bot sent to the Bot API: volume, latency, failures and flood limits.',
                tools: this.periodPicker(),
            })}

            <div class="grid cols-4">
                ${tile({ label: 'Calls', value: t.api_call, iconName: 'send', tone: 'info' })}
                ${tile({ label: 'Error rate', value: errorRate, format: (v) => fmt.percent(v), iconName: 'alertTriangle', tone: errorRate > 5 ? 'error' : 'success' })}
                ${tile({ label: 'Average duration', value: data.avg, format: fmt.duration, iconName: 'timer', tone: 'warning' })}
                ${tile({ label: 'Flood waits', value: t.flood_wait, iconName: 'flame', tone: t.flood_wait ? 'error' : '', href: '#/entries/api_call?tag=flood' })}
            </div>

            <div class="grid cols-2">
                ${card({ title: 'Calls over time', actions: legend([{ label: 'Calls', color: SERIES[2] }, { label: 'Failed', color: SERIES[7] }]), body: html`<div data-chart="calls"></div>` })}
                ${card({ title: 'Average duration', sub: 'Round trip to the Bot API server', body: html`<div data-chart="duration"></div>` })}
            </div>

            ${card({
                title: 'Methods',
                flush: data.methods.length > 0,
                body: data.methods.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Method</th><th class="num">Calls</th><th class="num">Share</th><th class="num">Avg</th><th class="num">Max</th></tr></thead>
                        <tbody>${data.methods.map((row) => html`
                            <tr class="clickable" data-href="#/entries/api_call?tag=method:${row.key}">
                                <td class="cell-main mono">${row.key}</td>
                                <td class="num strong">${fmt.number(row.count)}</td>
                                <td class="num muted">${fmt.percent((row.count / Math.max(1, t.api_call)) * 100)}</td>
                                <td class="num">${fmt.duration(row.avg)}</td>
                                <td class="num muted">${fmt.duration(row.max)}</td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ iconName: 'send', title: 'No API calls in this period' }),
            })}

            <div class="grid cols-2">
                ${card({
                    title: 'Errors',
                    sub: 'Grouped by method, code and description',
                    flush: data.errors.length > 0,
                    body: data.errors.length ? html`
                        <div class="table-wrap"><table class="table compact">
                            <tbody>${data.errors.map((row) => html`
                                <tr class="clickable" data-href="#/entries/api_call?tag=method:${row.method},error:${row.code}">
                                    <td><div class="cell-main"><span class="mono">${row.method}</span> <span class="badge ${row.code === 429 ? 'warning' : 'error'}">${row.code}</span></div><div class="cell-sub">${row.description}</div></td>
                                    <td class="num strong">${fmt.number(row.count)}</td>
                                </tr>`)}</tbody>
                        </table></div>` : empty({ compact: true, iconName: 'checkCircle', title: 'No failed calls' }),
                })}
                ${card({
                    title: 'Flood control',
                    sub: 'Methods Telegram told the bot to slow down on',
                    flush: data.flood.length > 0,
                    body: data.flood.length ? html`
                        <div class="table-wrap"><table class="table compact">
                            <thead><tr><th>Method</th><th class="num">Times</th><th class="num">Longest wait</th><th class="num">Total wait</th></tr></thead>
                            <tbody>${data.flood.map((row) => html`
                                <tr class="clickable" data-href="#/entries/api_call?tag=method:${row.key},flood">
                                    <td class="cell-main mono">${row.key}</td>
                                    <td class="num">${fmt.number(row.count)}</td>
                                    <td class="num strong">${fmt.seconds(row.max)}</td>
                                    <td class="num muted">${fmt.seconds(row.sum)}</td>
                                </tr>`)}</tbody>
                        </table></div>
                        <div class="card-footer row">${icon('info')} Turn on <code>bot.anti_flood</code> to pace calls before Telegram has to.</div>` : empty({ compact: true, iconName: 'flame', title: 'No flood waits', text: 'Telegram has not rate-limited the bot in this period.' }),
                })}
            </div>

            ${card({
                title: 'Latest calls',
                actions: html`<a class="btn xs outline" href="#/entries/api_call">View all</a>`,
                flush: data.recent.length > 0,
                body: data.recent.length ? entriesTable(data.recent, { heading: 'Call' }) : empty({ compact: true, iconName: 'send', title: 'No calls recorded' }),
            })}`;
    }
}
