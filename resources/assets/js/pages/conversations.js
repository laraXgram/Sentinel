import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { store } from '../core/store.js';
import { raw } from '../core/dom.js';
import * as fmt from '../core/format.js';
import { card, pageHead, empty } from '../components/ui.js';
import { SERIES } from '../components/charts.js';
import { entriesTable } from '../components/entries.js';

const STEPS = [
    ['started', 'Started'],
    ['answered', 'Answers'],
    ['invalid', 'Invalid answers'],
    ['back', 'Went back'],
    ['skipped', 'Skipped'],
    ['completed', 'Completed'],
    ['cancelled', 'Cancelled'],
];

export default class ConversationsPage extends Page {
    static live = true;

    title() {
        return 'Conversations';
    }

    async load() {
        const [metrics, recent] = await Promise.all([
            api.get('metrics/conversations', store.query()),
            api.get('entries/type/conversation', { take: 15 }),
        ]);

        return { ...metrics, recent: recent.entries };
    }

    charts(data) {
        return {
            activity: { buckets: data.activity.buckets, series: [{ name: 'Conversation events', color: SERIES[6], values: data.activity.series.conversation || [] }], period: store.get('period'), height: 200 },
        };
    }

    view(data) {
        return html`
            ${pageHead({
                title: 'Conversations',
                sub: 'Multi-step conversations as funnels: how many users finish, and where the rest give up.',
                tools: this.periodPicker(),
            })}

            ${data.funnels.length ? html`<div class="grid cols-2">${data.funnels.map((funnel) => this.funnel(funnel))}</div>` : html`<div class="card">${empty({ iconName: 'conversation', title: 'No conversations in this period', text: 'Conversations started with the Conversation facade appear here with their completion rate.' })}</div>`}

            ${card({ title: 'Activity', body: html`<div data-chart="activity"></div>` })}

            ${card({
                title: 'Latest events',
                actions: html`<a class="btn xs outline" href="#/entries/conversation">View all</a>`,
                flush: data.recent.length > 0,
                body: data.recent.length ? entriesTable(data.recent, { heading: 'Event' }) : empty({ compact: true, iconName: 'conversation', title: 'No events recorded' }),
            })}`;
    }

    funnel(funnel) {
        const started = funnel.steps.started || 0;
        const max = Math.max(1, ...Object.values(funnel.steps));
        const tone = funnel.completion === null ? '' : funnel.completion >= 60 ? 'success' : funnel.completion >= 30 ? 'warning' : 'error';

        return card({
            title: funnel.name,
            sub: `${fmt.plural(started, 'start')} in the ${fmt.periodName(store.get('period'))}`,
            actions: html`<span class="badge ${tone} lg">${funnel.completion === null ? 'no starts' : `${fmt.percent(funnel.completion)} complete`}</span>`,
            body: html`
                <div class="bars">
                    ${STEPS.filter(([step]) => funnel.steps[step]).map(([step, label]) => {
                        const value = funnel.steps[step];
                        const color = step === 'completed' ? 'var(--success-500)' : step === 'cancelled' || step === 'invalid' ? 'var(--error-500)' : 'var(--brand-500)';

                        return html`
                            <a class="bar-row" href="#/entries/conversation?tag=conversation:${funnel.name},step:${step}">
                                <div class="bar-label"><span>${label}</span></div>
                                <div class="bar-value">${fmt.number(value)}${started && ['completed', 'cancelled'].includes(step) ? html` <small>${fmt.percent((value / started) * 100)}</small>` : ''}</div>
                                <div class="bar-track"><div class="bar-fill" style="width:${((value / max) * 100).toFixed(1)}%;background:${raw(color)}"></div></div>
                            </a>`;
                    })}
                </div>`,
        });
    }
}
