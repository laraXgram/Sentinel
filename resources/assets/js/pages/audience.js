import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import { raw } from '../core/dom.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, empty, identity, barList } from '../components/ui.js';
import { legend, SERIES } from '../components/charts.js';
import { personName, personSub } from '../components/telegram.js';

export default class AudiencePage extends Page {
    static live = true;

    setup() {
        this.filter = '';

        this.on('input', 'input[data-action=filter-people]', (e, input) => {
            this.filter = input.value.toLowerCase();
            this.el.querySelectorAll('tr[data-search]').forEach((row) => {
                row.style.display = row.dataset.search.includes(this.filter) ? '' : 'none';
            });
        });
    }

    get kind() {
        return this.params.kind === 'chat' ? 'chat' : 'user';
    }

    title() {
        return this.kind === 'chat' ? 'Chats' : 'Users';
    }

    load() {
        return api.get('metrics/audience', store.query({ kind: this.kind }));
    }

    charts(data) {
        const growth = data.growth.series;

        return {
            growth: {
                buckets: data.growth.buckets,
                series: [
                    { name: this.kind === 'chat' ? 'Updates from chats' : 'Updates from users', color: SERIES[0], values: growth[this.kind] || [], area: true },
                    { name: this.kind === 'chat' ? 'New chats' : 'New users', color: SERIES[2], values: growth[`new_${this.kind}`] || [] },
                ],
                bars: false,
                period: store.get('period'),
                height: 250,
            },
        };
    }

    view(data) {
        const chat = this.kind === 'chat';
        const noun = chat ? 'chat' : 'user';

        return html`
            ${pageHead({
                title: chat ? 'Chats' : 'Users',
                sub: chat ? 'Private chats, groups and channels your bot talks in.' : 'People who talk to your bot, and who stopped listening.',
                tools: this.periodPicker(),
            })}

            <div class="grid cols-4">
                ${tile({ label: `Known ${noun}s`, value: data.known, iconName: chat ? 'chats' : 'users', tone: 'brand' })}
                ${tile({ label: `Active in period`, value: data.active, iconName: 'activity', tone: 'info' })}
                ${tile({ label: `New ${noun}s`, value: data.new, iconName: 'userPlus', tone: 'success' })}
                ${chat
                    ? tile({ label: 'Blocked deliveries', value: data.blocked, iconName: 'ban', tone: data.blocked ? 'error' : '', href: '#/entries/api_call?tag=blocked' })
                    : tile({ label: 'Blocked the bot', value: data.blocked, iconName: 'ban', tone: data.blocked ? 'error' : '', href: '#/entries/api_call?tag=blocked' })}
            </div>

            <div class="grid cols-12">
                <div class="span-8">
                    ${card({
                        title: 'Activity & growth',
                        actions: legend([{ label: 'Updates', color: SERIES[0] }, { label: `New ${noun}s`, color: SERIES[2] }]),
                        body: html`<div data-chart="growth"></div>`,
                    })}
                </div>
                <div class="span-4">
                    ${chat ? card({
                        title: 'Chat types',
                        body: barList(Object.entries(data.chat_types).map(([type, count]) => ({ label: fmt.title(type), value: count })), { empty: 'No chats yet' }),
                    }) : card({
                        title: 'Languages',
                        sub: data.premium !== null ? `${fmt.number(data.premium)} Telegram Premium users` : '',
                        body: barList(Object.entries(data.languages).map(([language, count]) => ({ label: language, value: count })), { empty: 'No users yet' }),
                    })}
                </div>
            </div>

            ${card({
                title: `Most active ${noun}s`,
                sub: `Ranked by updates in the ${fmt.periodName(store.get('period'))}`,
                actions: html`<div class="search" style="flex-basis:240px">${icon('search')}<input type="search" data-action="filter-people" placeholder="Filter by name or ID" style="height:36px"></div>`,
                flush: data.rows.length > 0,
                body: data.rows.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>${chat ? 'Chat' : 'User'}</th>${chat ? html`<th>Type</th>` : html`<th class="hide-sm">Language</th>`}<th class="num">Updates</th><th class="num hide-sm">First seen</th><th class="num">Last seen</th></tr></thead>
                        <tbody>${data.rows.map((row) => {
                            const meta = row.meta || { id: row.key };
                            const name = personName(meta);

                            return html`
                                <tr class="clickable" data-href="#/entries?tag=${noun}:${row.key}" data-search="${`${name} ${meta.username || ''} ${row.key}`.toLowerCase()}">
                                    <td><div class="row">${identity(name, personSub(meta) || row.key, row.key)}${meta.is_premium ? raw(' <span title="Premium">⭐</span>') : ''}${row.blocked ? html`<span class="badge error">${icon('ban')}blocked</span>` : ''}</div></td>
                                    ${chat ? html`<td><span class="badge">${meta.type || '—'}</span></td>` : html`<td class="hide-sm muted">${meta.language_code || '—'}</td>`}
                                    <td class="num strong">${fmt.number(row.count)}</td>
                                    <td class="num muted hide-sm">${fmt.ago(meta.first_seen)}</td>
                                    <td class="num muted">${fmt.ago(meta.last_seen)}</td>
                                </tr>`;
                        })}</tbody>
                    </table></div>` : empty({ iconName: chat ? 'chats' : 'users', title: `No ${noun}s in this period` }),
            })}

            ${data.recent.length ? card({
                title: `Newest ${noun}s`,
                body: html`<div class="grid cols-4" style="gap:16px">${data.recent.map((item) => html`
                    <a class="card" href="#/entries?tag=${noun}:${item.key}" style="padding:14px">${identity(personName(item.value), `joined ${fmt.ago(item.value.first_seen)}`, item.key)}</a>`)}</div>`,
            }) : ''}`;
    }
}
