import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, pageHead, props, empty, gauge, avatar, modal, toast, badge, skeleton } from '../components/ui.js';
import { SERIES } from '../components/charts.js';

function healthBadge(status) {
    const map = {
        healthy: ['success', 'Healthy', 'checkCircle'],
        warning: ['warning', 'Needs attention', 'alertTriangle'],
        error: ['error', 'Failing', 'alertCircle'],
        inactive: ['', 'No webhook', 'pause'],
    };
    const [tone, label, iconName] = map[status] || ['', 'Unknown', 'info'];

    return html`<span class="badge ${tone}">${icon(iconName)}${label}</span>`;
}

function issues(list, limit = null) {
    const shown = limit ? (list || []).slice(0, limit) : list || [];

    if (!shown.length) {
        return html`<div class="issue success">${icon('checkCircle')}<div><div class="issue-title">Everything looks good</div><div class="issue-detail">Telegram delivers updates to your webhook without errors.</div></div></div>`;
    }

    return html`${shown.map((issue) => html`
        <div class="issue ${issue.level}">
            ${icon(issue.level === 'error' ? 'alertCircle' : issue.level === 'warning' ? 'alertTriangle' : 'info')}
            <div class="grow"><div class="issue-title">${issue.title}</div><div class="issue-detail">${issue.detail}</div></div>
        </div>`)}`;
}

export class BotsPage extends Page {
    title() {
        return 'Bots & Webhooks';
    }

    setup() {
        this.on('click', '[data-action=reload]', () => this.render());
    }

    loading() {
        return skeleton({ tiles: 0, blocks: 1 });
    }

    load() {
        return api.get('bots');
    }

    view(data) {
        return html`
            ${pageHead({
                title: 'Bots & Webhooks',
                sub: 'Live status straight from Telegram for every bot connection in config/bot.php.',
                tools: html`<button class="btn outline sm" data-action="reload">${icon('refresh')} Check again</button>`,
            })}
            ${data.bots.length ? html`<div class="grid cols-2">${data.bots.map((bot) => this.bot(bot))}</div>` : html`<div class="card">${empty({ iconName: 'bot', title: 'No bot connections', text: 'Add a connection to config/bot.php to inspect it here.' })}</div>`}`;
    }

    bot(bot) {
        const me = bot.me || {};
        const webhook = bot.webhook || {};
        const info = webhook.info || {};
        const health = webhook.health || { status: 'error', score: 0, issues: [] };
        const name = me.first_name || bot.name;

        if (!bot.configured) {
            return html`<div class="card">${empty({ iconName: 'key', title: `${bot.name} has no token`, text: 'Set the token of this connection in your .env file.' })}</div>`;
        }

        return html`
            <section class="card bot-card">
                <div class="bot-card-head">
                    ${avatar(name, me.id || bot.bot_id, 'lg')}
                    <div class="grow" style="min-width:0;position:relative;z-index:1">
                        <div class="row"><span class="strong truncate" style="font-size:17px">${name}</span>${bot.default ? badge('default', 'brand') : ''}</div>
                        <div class="muted small">${me.username ? `@${me.username} · ` : ''}connection <b>${bot.name}</b> · <span class="mono">${bot.token}</span></div>
                    </div>
                    ${healthBadge(health.status)}
                </div>
                <div class="card-body">
                    <div class="row" style="gap:20px;align-items:center">
                        ${gauge(health.score, health.status)}
                        <div class="grow" style="min-width:0">
                            <div class="section-title" style="margin-bottom:6px">Webhook</div>
                            ${info.url ? html`<div class="url-box"><span>${info.url}</span><button class="btn xs ghost icon" data-action="copy" data-value="${info.url}" aria-label="Copy">${icon('copy')}</button></div>` : html`<div class="muted">Not set${webhook.error ? ` — ${webhook.error}` : ''}</div>`}
                            <div class="mt-4">${issues(health.issues, 2)}</div>
                        </div>
                    </div>
                </div>
                <div class="bot-stats" style="border-top:1px solid var(--border)">
                    <div class="bot-stat"><span>Pending updates</span><strong>${fmt.number(info.pending_update_count ?? 0)}</strong></div>
                    <div class="bot-stat"><span>Max connections</span><strong>${info.max_connections ?? '—'}</strong></div>
                    <div class="bot-stat"><span>API latency</span><strong>${fmt.duration(webhook.latency)}</strong></div>
                </div>
                <div class="card-footer row between">
                    <span>${info.ip_address ? html`Resolved to <span class="mono">${info.ip_address}</span>` : 'Checked just now'}</span>
                    <a class="btn sm primary" href="#/bots/${bot.name}">Inspect ${icon('arrowRight')}</a>
                </div>
            </section>`;
    }
}

const UPDATE_TYPES = ['message', 'edited_message', 'channel_post', 'edited_channel_post', 'callback_query', 'inline_query', 'chosen_inline_result', 'my_chat_member', 'chat_member', 'chat_join_request', 'message_reaction', 'poll', 'poll_answer', 'pre_checkout_query', 'shipping_query', 'business_message'];

export class BotPage extends Page {
    title() {
        return this.params.connection;
    }

    setup() {
        this.on('click', '[data-action=reload]', () => this.render({ soft: true }));
        this.on('click', '[data-action=set-webhook]', () => this.setWebhook());
        this.on('click', '[data-action=delete-webhook]', () => this.deleteWebhook());
        this.on('click', '[data-action=drop-pending]', () => this.dropPending());
    }

    loading() {
        return skeleton({ tiles: 0, blocks: 3 });
    }

    load() {
        return api.get(`bots/${encodeURIComponent(this.params.connection)}`, { period: store.get('period') });
    }

    charts(data) {
        return {
            pending: { buckets: data.history.buckets, series: [{ name: 'Pending updates', color: SERIES[3], values: data.history.series.pending }], period: store.get('period'), height: 200, format: fmt.compact },
            latency: { buckets: data.history.buckets, series: [{ name: 'getWebhookInfo latency', color: SERIES[0], values: data.history.series.latency }], period: store.get('period'), height: 200, format: fmt.duration },
        };
    }

    view(data) {
        const me = data.profile.me || {};
        const webhook = data.webhook || {};
        const info = webhook.info || {};
        const health = webhook.health || {};
        const allowChanges = window.Sentinel.webhook_changes;
        const hasHistory = data.history.buckets.length > 0;

        const capabilities = [
            ['can_join_groups', 'Joins groups'],
            ['can_read_all_group_messages', 'Reads all group messages'],
            ['supports_inline_queries', 'Inline mode'],
            ['can_connect_to_business', 'Business'],
            ['has_main_web_app', 'Main Mini App'],
        ];

        return html`
            ${pageHead({
                crumbs: [{ label: 'Bots & Webhooks', href: '#/bots' }, { label: this.params.connection }],
                title: me.first_name || this.params.connection,
                sub: html`${me.username ? `@${me.username} · ` : ''}ID <span class="mono">${me.id || data.connection.bot_id}</span> · connection <b>${this.params.connection}</b>`,
                tools: html`${this.periodPicker()}<button class="btn outline sm" data-action="reload">${icon('refresh')} Refresh</button>`,
            })}

            ${data.profile.error ? html`<div class="issue error mb-4">${icon('alertCircle')}<div><div class="issue-title">getMe failed</div><div class="issue-detail">${data.profile.error}</div></div></div>` : ''}

            <div class="grid cols-12">
                <div class="span-4">
                    ${card({
                        className: 'accent',
                        body: html`
                            <div class="row" style="gap:16px">
                                ${avatar(me.first_name || this.params.connection, me.id, 'xl')}
                                <div class="grow" style="min-width:0">
                                    <div class="strong truncate" style="font-size:18px">${me.first_name || this.params.connection}</div>
                                    ${me.username ? html`<a class="small" style="color:var(--brand-500)" href="https://t.me/${me.username}" target="_blank" rel="noopener">t.me/${me.username}</a>` : ''}
                                </div>
                            </div>
                            ${data.profile.short_description ? html`<p class="muted" style="margin:16px 0 0">${data.profile.short_description}</p>` : ''}
                            <div class="chips mt-4">${capabilities.map(([key, label]) => html`<span class="badge ${me[key] ? 'success' : ''}">${icon(me[key] ? 'check' : 'x')}${label}</span>`)}</div>
                            ${data.profile.description ? html`<div class="divider"></div><div class="section-title">Description</div><p class="small muted" style="margin:0;white-space:pre-wrap">${data.profile.description}</p>` : ''}
                            ${data.profile.menu_button ? html`<div class="divider"></div><div class="section-title">Menu button</div><span class="badge">${data.profile.menu_button.type}${data.profile.menu_button.text ? `: ${data.profile.menu_button.text}` : ''}</span>` : ''}`,
                    })}
                </div>

                <div class="span-8">
                    ${card({
                        title: 'Webhook health',
                        sub: `Answered in ${fmt.duration(webhook.latency)}`,
                        actions: healthBadge(health.status),
                        body: html`
                            <div class="row" style="gap:24px;align-items:flex-start">
                                <div class="hide-sm">${gauge(health.score, health.status)}</div>
                                <div class="grow" style="min-width:0">${issues(health.issues)}</div>
                            </div>`,
                    })}
                </div>
            </div>

            <div class="grid cols-12">
                <div class="span-7">
                    ${card({
                        title: 'Webhook',
                        actions: allowChanges ? html`
                            <button class="btn xs outline" data-action="set-webhook">${icon('edit')} ${info.url ? 'Change' : 'Set webhook'}</button>
                            ${info.url ? html`<button class="btn xs outline" data-action="drop-pending" ${info.pending_update_count ? '' : 'disabled'}>${icon('trash')} Drop pending</button>
                            <button class="btn xs danger-outline" data-action="delete-webhook">${icon('x')} Delete</button>` : ''}` : html`<span class="badge">${icon('lock')} read only</span>`,
                        body: props([
                            ['URL', info.url ? html`<div class="url-box"><span>${info.url}</span><button class="btn xs ghost icon" data-action="copy" data-value="${info.url}" aria-label="Copy">${icon('copy')}</button></div>` : html`<span class="muted">Not set</span>`],
                            ['Bot API server', data.api_server.local ? html`${badge('local', 'info')} <span class="mono small">${data.api_server.endpoint}</span>` : html`<span class="mono small">api.telegram.org</span>`],
                            ['In config', data.connection.url ? html`<span class="mono small">${data.connection.url}</span>` : html`<span class="muted">bot.connections.${this.params.connection}.url is empty</span>`],
                            ['Pending updates', html`<b>${fmt.number(info.pending_update_count ?? 0)}</b>`],
                            ['Max connections', info.max_connections],
                            ['IP address', info.ip_address ? html`<span class="mono">${info.ip_address}</span>` : null],
                            ['Allowed updates', info.allowed_updates ? html`<div class="chips">${info.allowed_updates.map((type) => html`<span class="tag">${type}</span>`)}</div>` : html`<span class="muted">All except chat_member, message_reaction and message_reaction_count</span>`],
                            ['Secret token', data.connection.secret_token ? badge('configured', 'success') : badge('not set', 'warning')],
                            ['Last error', info.last_error_date ? html`<span style="color:var(--error-600)">${info.last_error_message}</span> <span class="muted small">· ${fmt.ago(info.last_error_date)}</span>` : html`<span class="muted">None</span>`],
                            ['Custom certificate', info.has_custom_certificate ? 'Yes' : 'No'],
                        ]),
                    })}
                </div>
                <div class="span-5">
                    ${this.commands(data)}
                </div>
            </div>

            <div class="grid cols-2">
                ${card({ title: 'Pending updates', sub: 'Highest count per interval', body: hasHistory ? html`<div data-chart="pending"></div>` : this.historyHint() })}
                ${card({ title: 'Telegram API latency', sub: 'How long getWebhookInfo took', body: hasHistory ? html`<div data-chart="latency"></div>` : this.historyHint() })}
            </div>`;
    }

    historyHint() {
        return empty({
            compact: true,
            iconName: 'clock',
            title: 'No history yet',
            text: html`Run <code>php laragram sentinel:check</code> (for example under Supervisor) to snapshot the webhook every ${30} seconds.`,
        });
    }

    commands(data) {
        const scopes = Object.entries(data.profile.commands || {});
        const diff = data.commands;

        return card({
            title: 'Commands',
            sub: 'Registered with BotFather vs. handled by your listeners',
            body: html`
                ${diff.unhandled.length ? html`<div class="issue warning">${icon('alertTriangle')}<div><div class="issue-title">Registered but not handled</div><div class="issue-detail">Users see these in the menu but nothing answers: ${diff.unhandled.map((c) => html`<span class="tag">/${c}</span> `)}</div></div></div>` : ''}
                ${diff.unregistered.length ? html`<div class="issue info">${icon('info')}<div><div class="issue-title">Handled but not in the menu</div><div class="issue-detail">Add them with setMyCommands so users can discover them: ${diff.unregistered.map((c) => html`<span class="tag">/${c}</span> `)}</div></div></div>` : ''}
                ${scopes.length ? scopes.map(([scope, commands]) => html`
                    <div class="section-title mt-4">${fmt.title(scope)}</div>
                    <div class="list">${commands.map((command) => html`
                        <div class="list-item" style="padding:8px 0">
                            <span class="mono strong">/${command.command}</span>
                            <span class="grow muted small truncate">${command.description}</span>
                            ${diff.matched.includes(command.command.toLowerCase()) ? html`<span class="badge success">${icon('check')}handled</span>` : html`<span class="badge warning">no listener</span>`}
                        </div>`)}</div>`) : empty({ compact: true, iconName: 'command', title: 'No commands registered', text: 'Register commands with setMyCommands so they appear in the Telegram menu.' })}`,
        });
    }

    async setWebhook() {
        const info = this.data.webhook.info || {};
        const url = info.url || this.data.connection.url || '';
        const allowed = info.allowed_updates || [];

        await modal({
            title: info.url ? 'Change webhook' : 'Set webhook',
            text: `Telegram will deliver updates for ${this.params.connection} to this URL. The secret token from your config is sent along.`,
            confirm: 'Save webhook',
            body: html`
                <div class="field"><label>URL</label><input class="input" name="url" type="url" required value="${url}" placeholder="https://example.com/"></div>
                <div class="field"><label>Max connections</label><input class="input" name="max_connections" type="number" min="1" max="100" value="${info.max_connections || 40}"></div>
                <div class="field">
                    <span class="field-label">Allowed updates</span>
                    <div class="chips">${UPDATE_TYPES.map((type) => html`<label class="checkbox" style="font-size:13px;min-width:170px"><input type="checkbox" name="allowed[${type}]" ${allowed.length === 0 || allowed.includes(type) ? 'checked' : ''}> ${type}</label>`)}</div>
                </div>
                <label class="checkbox"><input type="checkbox" name="drop_pending_updates"> Drop pending updates</label>`,
            onConfirm: async (values) => {
                const allowedUpdates = Object.keys(values).filter((key) => key.startsWith('allowed[')).map((key) => key.slice(8, -1));

                await api.post(`bots/${encodeURIComponent(this.params.connection)}/webhook`, {
                    url: values.url,
                    max_connections: values.max_connections,
                    allowed_updates: allowedUpdates,
                    drop_pending_updates: values.drop_pending_updates === 'on',
                });

                toast('Webhook saved', values.url);
                this.render({ soft: true });
            },
        });
    }

    async deleteWebhook() {
        await modal({
            title: 'Delete webhook?',
            text: 'Telegram stops delivering updates to your server until you set a webhook again or poll with getUpdates.',
            confirm: 'Delete webhook',
            tone: 'danger',
            body: html`<label class="checkbox"><input type="checkbox" name="drop_pending_updates"> Also drop the ${fmt.number(this.data.webhook.info?.pending_update_count || 0)} pending updates</label>`,
            onConfirm: async (values) => {
                await api.delete(`bots/${encodeURIComponent(this.params.connection)}/webhook`, { drop_pending_updates: values.drop_pending_updates === 'on' });
                toast('Webhook deleted');
                this.render({ soft: true });
            },
        });
    }

    async dropPending() {
        const info = this.data.webhook.info || {};

        await modal({
            title: `Drop ${fmt.plural(info.pending_update_count || 0, 'pending update')}?`,
            text: 'The webhook stays as it is; updates Telegram is still holding are thrown away and will never reach your bot.',
            confirm: 'Drop updates',
            tone: 'danger',
            onConfirm: async () => {
                await api.post(`bots/${encodeURIComponent(this.params.connection)}/webhook`, {
                    url: info.url,
                    max_connections: info.max_connections,
                    allowed_updates: info.allowed_updates,
                    drop_pending_updates: true,
                });
                toast('Pending updates dropped');
                this.render({ soft: true });
            },
        });
    }
}
