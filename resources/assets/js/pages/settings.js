import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, pageHead, empty, modal, toast, badge, props } from '../components/ui.js';
import { TYPES } from '../components/entries.js';

export default class SettingsPage extends Page {
    setup() {
        this.on('click', '[data-action=toggle-recording-page]', async () => {
            const result = await api.post('recording');
            store.set({ recording: result.recording });
            this.render({ soft: true });
        });

        this.on('click', '[data-action=clear]', async (e, button) => {
            const metrics = button.dataset.metrics === '1';

            await modal({
                title: metrics ? 'Clear entries and metrics?' : 'Clear all entries?',
                text: metrics ? 'Every recorded entry and every chart goes away. Known users and chats are kept.' : 'Every recorded entry is deleted. Metrics and charts are kept.',
                confirm: 'Clear',
                tone: 'danger',
                onConfirm: async () => {
                    await api.delete('entries', { metrics });
                    toast('Cleared');
                    this.render({ soft: true });
                },
            });
        });

        this.on('submit', 'form[data-action=monitor]', async (e, form) => {
            e.preventDefault();
            const tag = form.tag.value.trim();
            if (!tag) return;
            await api.post('monitoring', { tag });
            toast('Monitoring', tag);
            this.render({ soft: true });
        });

        this.on('click', '[data-action=unmonitor]', async (e, button) => {
            await api.delete('monitoring', { tag: button.dataset.tag });
            this.render({ soft: true });
        });
    }

    title() {
        return 'Settings';
    }

    load() {
        return api.get('status');
    }

    view(data) {
        const total = Object.values(data.counts).reduce((sum, count) => sum + count, 0);
        const config = window.Sentinel;

        return html`
            ${pageHead({ title: 'Settings', sub: 'Recording, storage and what Sentinel watches. Everything else lives in config/sentinel.php.' })}

            <div class="grid cols-2">
                ${card({
                    title: 'Recording',
                    actions: data.recording ? badge('recording', 'success', 'radio') : badge('paused', 'warning', 'pause'),
                    body: html`
                        <p class="muted" style="margin-top:0">${data.recording ? 'Sentinel records every update, API call and exception right now.' : 'Recording is paused. Nothing new is stored until you resume.'}</p>
                        <button class="btn ${data.recording ? 'outline' : 'primary'}" data-action="toggle-recording-page">${icon(data.recording ? 'pause' : 'play')} ${data.recording ? 'Pause recording' : 'Resume recording'}</button>
                        <div class="divider"></div>
                        ${props([
                            ['Version', `v${data.version}`],
                            ['Environment', config.env],
                            ['Entries kept', `${data.retention.entries} hours`],
                            ['Metrics kept', `${data.retention.metrics} days`],
                            ['Telegram alerts', data.alerts.enabled ? badge(`on · ${fmt.plural(data.alerts.chats, 'chat')}`, 'success') : badge('off')],
                            ['Playground', config.playground ? badge(config.allow_live ? 'dry run + live' : 'dry run only', 'brand') : badge('disabled')],
                            ['Webhook changes', config.webhook_changes ? badge('allowed', 'warning') : badge('read only')],
                        ])}`,
                })}

                ${card({
                    title: 'Storage',
                    sub: `${fmt.plural(total, 'entry')} stored`,
                    actions: html`<button class="btn xs danger-outline" data-action="clear">${icon('trash')} Clear entries</button><button class="btn xs danger-outline" data-action="clear" data-metrics="1">Clear all</button>`,
                    body: total ? html`<div class="list">${Object.entries(data.counts).sort((a, b) => b[1] - a[1]).map(([type, count]) => html`
                        <a class="list-item" href="#/entries/${type}"><span class="type-icon ${TYPES[type]?.tone || ''}">${icon(TYPES[type]?.icon || 'info')}</span><span class="grow list-title">${TYPES[type]?.label || type}</span><span class="strong tabular">${fmt.number(count)}</span></a>`)}</div>` : empty({ compact: true, title: 'Nothing stored yet' }),
                    footer: html`Schedule <code>php laragram sentinel:prune</code> daily to keep the tables small.`,
                })}
            </div>

            <div class="grid cols-2">
                ${card({
                    title: 'Monitored tags',
                    sub: 'Entries with these tags are always recorded, even when a filter would skip them',
                    body: html`
                        <form class="row" data-action="monitor"><input class="input" name="tag" placeholder="user:123456 or chat:-100…"><button class="btn primary" type="submit">${icon('eye')} Monitor</button></form>
                        <div class="list mt-4">${data.monitoring.length ? data.monitoring.map((tag) => html`
                            <div class="list-item"><span class="tag">${tag}</span><span class="grow"></span><a class="btn xs outline" href="#/entries?tag=${encodeURIComponent(tag)}">Entries</a><button class="btn xs ghost icon" data-action="unmonitor" data-tag="${tag}" aria-label="Stop monitoring">${icon('x')}</button></div>`) : html`<div class="muted small">No tags are monitored.</div>`}</div>`,
                })}
                ${card({
                    title: 'Watchers',
                    sub: 'Toggle them in config/sentinel.php',
                    body: html`<div class="list">${data.watchers.map((watcher) => html`<div class="list-item"><span class="status-dot ${watcher.enabled ? 'success' : ''}"></span><span class="grow mono small">${watcher.name}</span>${watcher.enabled ? badge('on', 'success') : badge('off')}</div>`)}</div>`,
                })}
            </div>`;
    }
}
