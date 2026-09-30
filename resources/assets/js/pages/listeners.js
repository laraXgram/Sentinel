import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, pageHead, empty, barList } from '../components/ui.js';

const VERB_TONES = { COMMAND: 'brand', TEXT: 'info', CALLBACK_DATA: 'warning', REFERRAL: 'success' };

export default class ListenersPage extends Page {
    static live = true;

    setup() {
        this.on('input', 'input[data-action=filter-listens]', (e, input) => {
            const value = input.value.toLowerCase();
            this.el.querySelectorAll('tr[data-search]').forEach((row) => {
                row.style.display = row.dataset.search.includes(value) ? '' : 'none';
            });
        });
    }

    title() {
        return 'Listeners';
    }

    load() {
        return api.get('metrics/listeners', store.query());
    }

    view(data) {
        const max = Math.max(...data.listens.map((l) => l.count), 1);
        const idle = data.listens.filter((l) => !l.count).length;

        return html`
            ${pageHead({
                title: 'Listeners',
                sub: `${fmt.plural(data.listens.length, 'registered listen')} · ${fmt.number(idle)} did not run in the ${fmt.periodName(store.get('period'))}`,
                tools: this.periodPicker(),
            })}

            ${card({
                title: 'Registered listens',
                sub: 'Everything in your listens files, with how often and how fast each one ran',
                actions: html`<div class="search" style="flex-basis:240px">${icon('search')}<input type="search" data-action="filter-listens" placeholder="Filter listens" style="height:36px"></div>`,
                flush: data.listens.length > 0,
                body: data.listens.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Listens to</th><th>Action</th><th class="num">Hits</th><th class="num">Avg</th><th class="num">Max</th></tr></thead>
                        <tbody>${data.listens.sort((a, b) => b.count - a.count).map((listen) => html`
                            <tr data-search="${`${listen.key} ${listen.name || ''} ${listen.action}`.toLowerCase()}" ${listen.name ? html`class="clickable" data-href="#/entries/update?tag=listen:${listen.name}"` : ''}>
                                <td>
                                    <div class="row wrap" style="gap:6px">${listen.verbs.map((verb) => html`<span class="badge ${VERB_TONES[verb] || ''}">${verb}</span>`)}<span class="cell-main mono">${listen.pattern || '*'}</span></div>
                                    ${listen.name ? html`<div class="cell-sub">${listen.name}</div>` : ''}
                                </td>
                                <td class="mono small muted" style="max-width:320px;word-break:break-all">${listen.action}</td>
                                <td class="num">
                                    <div class="strong">${fmt.number(listen.count)}</div>
                                    <div class="bar-track" style="width:80px;margin-left:auto;margin-top:4px;height:4px"><div class="bar-fill" style="width:${((listen.count / max) * 100).toFixed(1)}%"></div></div>
                                </td>
                                <td class="num">${listen.avg !== null ? fmt.duration(listen.avg) : html`<span class="faint">—</span>`}</td>
                                <td class="num muted">${listen.max !== null ? fmt.duration(listen.max) : html`<span class="faint">—</span>`}</td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ iconName: 'route', title: 'No listens registered', text: 'Listens you define in listens/bot.php show up here.' }),
            })}

            <div class="grid cols-2">
                ${card({
                    title: 'Unhandled updates',
                    sub: 'Updates no listen matched — a missing handler, or noise to filter with allowed_updates',
                    body: barList(data.unhandled.map((row) => ({ label: row.key, value: row.count })), {
                        color: 'var(--warning-500)',
                        empty: 'Every update found a listener',
                        href: () => '#/entries/update?tag=unhandled',
                    }),
                })}
                ${card({
                    title: 'Commands used',
                    body: barList(data.commands.map((row) => ({ label: row.key, value: row.count, extra: `${fmt.duration(row.avg)} avg` })), {
                        empty: 'No commands in this period',
                        href: (row) => `#/entries/update?tag=command:${row.label}`,
                    }),
                })}
            </div>

            ${data.unregistered.length ? card({
                title: 'Listens that no longer exist',
                sub: 'They ran in this period but are not registered anymore',
                body: barList(data.unregistered.map((row) => ({ label: row.key, value: row.count })), {}),
            }) : ''}`;
    }
}
