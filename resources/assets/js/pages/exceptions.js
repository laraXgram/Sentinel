import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, pageHead, empty } from '../components/ui.js';
import { SERIES } from '../components/charts.js';
import { entriesTable } from '../components/entries.js';

export default class ExceptionsPage extends Page {
    static live = true;

    title() {
        return 'Exceptions';
    }

    async load() {
        const [metrics, recent] = await Promise.all([
            api.get('metrics/exceptions', store.query()),
            api.get('entries/type/exception', { take: 20 }),
        ]);

        return { ...metrics, recent: recent.entries };
    }

    charts(data) {
        return {
            graph: { buckets: data.graph.buckets, series: [{ name: 'Exceptions', color: SERIES[7], values: data.graph.series.exception || [] }], period: store.get('period'), height: 200, bars: true },
        };
    }

    view(data) {
        const total = data.groups.reduce((sum, group) => sum + group.count, 0);

        return html`
            ${pageHead({
                title: 'Exceptions',
                sub: `${fmt.plural(total, 'exception')} of ${fmt.plural(data.groups.length, 'kind')} in the ${fmt.periodName(store.get('period'))}`,
                tools: this.periodPicker(),
            })}

            ${card({ title: 'Exceptions over time', body: html`<div data-chart="graph"></div>` })}

            ${card({
                title: 'Grouped by location',
                flush: data.groups.length > 0,
                body: data.groups.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Exception</th><th class="num">Count</th><th class="num">Last seen</th></tr></thead>
                        <tbody>${data.groups.map((group) => html`
                            <tr class="clickable" data-href="#/entries/exception?q=${encodeURIComponent(group.location || '')}">
                                <td>
                                    <div class="row" style="gap:12px">
                                        <span class="type-icon error">${icon('bug')}</span>
                                        <div style="min-width:0"><div class="cell-main">${(group.class || '').split('\\').pop()}</div><div class="cell-sub mono">${group.location}</div></div>
                                    </div>
                                </td>
                                <td class="num strong">${fmt.number(group.count)}</td>
                                <td class="num muted">${fmt.ago(group.max)}</td>
                            </tr>`)}</tbody>
                    </table></div>` : empty({ iconName: 'checkCircle', title: 'No exceptions', text: 'Nothing has been thrown in this period. Nice.' }),
            })}

            ${card({
                title: 'Latest exceptions',
                flush: data.recent.length > 0,
                body: data.recent.length ? entriesTable(data.recent, { heading: 'Exception' }) : empty({ compact: true, iconName: 'bug', title: 'No exceptions recorded' }),
            })}`;
    }
}
