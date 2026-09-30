import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { cls } from '../core/dom.js';
import * as fmt from '../core/format.js';
import { card, pageHead, empty, toast, badge, json } from '../components/ui.js';
import { chatPreview, personName, statusBadge } from '../components/telegram.js';
import { describe, entryHref, TYPES } from '../components/entries.js';

const KINDS = [
    ['text', 'Text', 'message', 'Message text', 'Hello'],
    ['command', 'Command', 'command', 'Command', 'start'],
    ['callback', 'Button', 'pointer', 'Callback data', 'menu:open'],
    ['inline_query', 'Inline', 'at', 'Inline query', 'cats'],
    ['photo', 'Photo', 'image', 'Caption (optional)', ''],
    ['location', 'Location', 'mapPin', 'Latitude, longitude', '35.6892,51.3890'],
    ['contact', 'Contact', 'phone', 'Phone number', '+989120000000'],
    ['my_chat_member', 'Membership', 'door', 'New status: member or kicked', 'member'],
    ['raw', 'Raw JSON', 'code', 'Update JSON', ''],
];

export default class PlaygroundPage extends Page {
    setup() {
        this.form = {
            kind: 'command',
            text: 'start',
            user_id: '',
            first_name: 'Sentinel',
            username: 'sentinel_tester',
            language_code: 'en',
            chat_type: 'private',
            chat_id: '',
            chat_title: '',
            connection: '',
            mode: 'dry',
            raw: '',
        };
        this.result = null;
        this.history = [];
        this.running = false;

        this.on('click', '[data-action=kind]', (e, button) => {
            this.read();
            const kind = KINDS.find(([key]) => key === button.dataset.value);
            this.form.kind = kind[0];
            this.form.text = kind[4];
            this.paint();
        });

        this.on('click', '[data-action=pick-user]', (e, button) => {
            this.read();
            const user = this.data.users[Number(button.dataset.index)];
            Object.assign(this.form, { user_id: user.id, first_name: user.first_name || '', username: user.username || '', language_code: user.language_code || 'en' });
            this.paint();
        });

        this.on('click', '[data-action=mode]', (e, button) => {
            this.read();
            this.form.mode = button.dataset.value;
            this.paint();
        });

        this.on('change', 'select[name=chat_type]', () => {
            this.read();
            this.paint();
        });

        this.on('submit', 'form[data-action=run]', async (e) => {
            e.preventDefault();
            this.read();
            await this.run();
        });

        this.on('click', '[data-action=history]', (e, button) => {
            this.result = this.history[Number(button.dataset.index)];
            this.paint();
        });
    }

    title() {
        return 'Playground';
    }

    load() {
        return api.get('playground');
    }

    read() {
        const form = this.el.querySelector('form[data-action=run]');

        if (!form) return;

        Object.entries(Object.fromEntries(new FormData(form).entries())).forEach(([key, value]) => {
            this.form[key] = value;
        });
    }

    async run() {
        this.running = true;
        this.paint();

        try {
            const payload = { ...this.form };

            if (payload.kind === 'raw') {
                try {
                    payload.raw = JSON.parse(payload.raw || '{}');
                } catch (error) {
                    throw new Error('The raw update is not valid JSON.');
                }
            }

            this.result = await api.post('playground', payload);
            this.result.form = { ...this.form };
            this.history.unshift(this.result);
            this.history = this.history.slice(0, 8);
        } catch (error) {
            toast('Could not run the update', error.message, 'error');
        }

        this.running = false;
        this.paint();
    }

    view(data) {
        if (!data.enabled) {
            return html`${pageHead({ title: 'Playground' })}<div class="card">${empty({ iconName: 'lock', title: 'The playground is disabled', text: 'Enable sentinel.playground.enabled to send fake updates through your bot.' })}</div>`;
        }

        const kind = KINDS.find(([key]) => key === this.form.kind);
        const f = this.form;

        return html`
            ${pageHead({
                title: 'Playground',
                sub: 'Send a fake update through your real bot code and watch what it answers — without messaging anyone.',
            })}

            <div class="grid cols-12">
                <div class="span-5 stack">
                    <form class="card" data-action="run">
                        <div class="card-header"><div><h3 class="card-title">Compose an update</h3><p class="card-sub">It runs in a separate process, exactly like a webhook delivery.</p></div></div>
                        <div class="card-body stack" style="gap:18px">
                            <div class="chips">${KINDS.map(([key, label, iconName]) => html`<button type="button" class="${cls('chip', f.kind === key && 'active')}" data-action="kind" data-value="${key}">${icon(iconName)}${label}</button>`)}</div>

                            <div class="field">
                                <label>${kind[3]}</label>
                                ${f.kind === 'raw'
                                    ? html`<textarea class="textarea" name="raw" rows="10" placeholder='{"message": {"text": "hi", "chat": {"id": 1, "type": "private"}, "from": {"id": 1, "first_name": "A"}}}'>${f.raw}</textarea>`
                                    : html`<input class="input" name="text" value="${f.text}" placeholder="${kind[4]}" autocomplete="off">`}
                                ${f.kind === 'command' ? html`<span class="field-hint">Without the slash; arguments allowed, e.g. <code>start ref_42</code>.</span>` : ''}
                                ${f.kind === 'callback' ? html`<span class="field-hint">The callback_data of the pressed inline button.</span>` : ''}
                            </div>

                            ${f.kind !== 'raw' ? html`
                                <div>
                                    <div class="section-title">From</div>
                                    ${data.users.length ? html`<div class="chips mb-4">${data.users.slice(0, 6).map((user, index) => html`<button type="button" class="${cls('chip', String(f.user_id) === String(user.id) && 'active')}" data-action="pick-user" data-index="${index}" title="${user.username ? `@${user.username} · ` : ''}${user.id}">${personName(user)}</button>`)}</div>` : ''}
                                    <div class="grid cols-2" style="gap:12px">
                                        <div class="field"><label>User ID</label><input class="input" name="user_id" value="${f.user_id}" placeholder="100000001" inputmode="numeric"></div>
                                        <div class="field"><label>First name</label><input class="input" name="first_name" value="${f.first_name}"></div>
                                        <div class="field"><label>Username</label><input class="input" name="username" value="${f.username}" placeholder="optional"></div>
                                        <div class="field"><label>Language</label><input class="input" name="language_code" value="${f.language_code}" maxlength="8"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="section-title">Chat</div>
                                    <div class="grid cols-2" style="gap:12px">
                                        <div class="field"><label>Type</label>
                                            <select class="select" name="chat_type">${['private', 'group', 'supergroup'].map((type) => html`<option value="${type}" ${f.chat_type === type ? 'selected' : ''}>${fmt.title(type)}</option>`)}</select>
                                        </div>
                                        ${data.connections.length > 1 ? html`<div class="field"><label>Bot</label><select class="select" name="connection"><option value="">Default</option>${data.connections.map((name) => html`<option value="${name}" ${f.connection === name ? 'selected' : ''}>${name}</option>`)}</select></div>` : ''}
                                        ${f.chat_type !== 'private' ? html`
                                            <div class="field"><label>Chat ID</label><input class="input" name="chat_id" value="${f.chat_id}" placeholder="-1001000000001"></div>
                                            <div class="field"><label>Title</label><input class="input" name="chat_title" value="${f.chat_title}" placeholder="Sentinel Group"></div>` : ''}
                                    </div>
                                </div>` : ''}

                            <div>
                                <div class="section-title">Mode</div>
                                <div class="grid cols-2" style="gap:12px">
                                    <button type="button" class="${cls('card', 'tile')}" data-action="mode" data-value="dry" style="text-align:left;padding:14px;${f.mode === 'dry' ? 'border-color:var(--brand-500);box-shadow:0 0 0 3px var(--focus-ring)' : ''}">
                                        <div class="strong row">${icon('shield')} Dry run</div>
                                        <div class="small muted mt-2">API calls are answered locally. Nobody receives anything.</div>
                                    </button>
                                    <button type="button" class="${cls('card', 'tile')}" data-action="mode" data-value="live" ${data.allow_live ? '' : 'disabled'} style="text-align:left;padding:14px;${!data.allow_live ? 'opacity:.55;cursor:not-allowed;' : ''}${f.mode === 'live' ? 'border-color:var(--error-500);box-shadow:0 0 0 3px rgba(240,68,56,.15)' : ''}">
                                        <div class="strong row">${icon('send')} Live</div>
                                        <div class="small muted mt-2">${data.allow_live ? 'Really sends the API calls to Telegram.' : 'Enable sentinel.playground.allow_live first.'}</div>
                                    </button>
                                </div>
                            </div>

                            <button class="${cls('btn', f.mode === 'live' ? 'danger' : 'primary', this.running && 'loading')}" type="submit">${icon(this.running ? 'refresh' : 'play')} ${this.running ? 'Running…' : f.mode === 'live' ? 'Send live update' : 'Run update'}</button>
                        </div>
                    </form>

                    ${this.history.length > 1 ? card({
                        title: 'This session',
                        body: html`<div class="list">${this.history.map((run, index) => {
                            const update = run.entries.find((e) => e.type === 'update');

                            return html`
                                <button class="list-item" data-action="history" data-index="${index}" style="width:100%;text-align:left">
                                    <span class="type-icon ${run === this.result ? 'brand' : ''}">${icon(KINDS.find(([key]) => key === run.form.kind)?.[2] || 'play')}</span>
                                    <div class="grow" style="min-width:0"><div class="list-title truncate">${update?.content.text || run.form.kind}</div><div class="list-sub">${run.mode} · ${fmt.duration(run.duration)}</div></div>
                                    ${update ? statusBadge(update.content.status) : badge('no update', 'error')}
                                </button>`;
                        })}</div>`,
                    }) : ''}
                </div>

                <div class="span-7 stack">${this.result ? this.resultView(this.result) : card({
                    body: empty({
                        iconName: 'play',
                        title: 'Nothing ran yet',
                        text: 'Pick an update type, fill in the text and press Run. You will see the chat as the user would, every API call, query and exception.',
                    }),
                })}</div>
            </div>`;
    }

    resultView(result) {
        const update = result.entries.find((e) => e.type === 'update');
        const calls = result.entries.filter((e) => e.type === 'api_call');
        const exceptions = result.entries.filter((e) => e.type === 'exception');
        const c = update?.content || {};

        return html`
            <div class="grid cols-4" style="gap:16px">
                <div class="card tile" style="padding:16px"><div class="tile-label">Status</div><div class="mt-2">${update ? statusBadge(c.status) : badge('crashed', 'error')}</div></div>
                <div class="card tile" style="padding:16px"><div class="tile-label">Handled in</div><div class="tile-value" style="font-size:22px">${fmt.duration(c.duration)}</div></div>
                <div class="card tile" style="padding:16px"><div class="tile-label">API calls</div><div class="tile-value" style="font-size:22px">${calls.length}</div></div>
                <div class="card tile" style="padding:16px"><div class="tile-label">Process</div><div class="tile-value" style="font-size:22px">${fmt.duration(result.duration)}</div></div>
            </div>

            ${exceptions.map((exception) => html`<a class="issue error" href="${entryHref(exception)}">${icon('bug')}<div><div class="issue-title">${exception.content.class}</div><div class="issue-detail">${exception.content.message} · ${exception.content.file}:${exception.content.line}</div></div></a>`)}
            ${!update ? html`<div class="issue error">${icon('alertCircle')}<div><div class="issue-title">The update was not recorded</div><div class="issue-detail">The process exited with code ${result.exit_code ?? '—'}. Its output is below.</div></div></div>` : ''}

            ${card({
                title: 'Chat preview',
                sub: result.mode === 'live' ? 'These messages were really sent' : 'Dry run: Telegram received nothing',
                actions: update ? html`<a class="btn xs outline" href="${entryHref(update)}">Open update ${icon('arrowRight')}</a>` : '',
                body: chatPreview({ update: result.update, summary: c.type ? c : { chat: result.update.message?.chat, date: result.update.message?.date }, calls }),
            })}

            ${c.listens?.length ? card({
                title: 'Handled by',
                body: html`<div class="list">${c.listens.map((listen) => html`<div class="list-item"><span class="type-icon brand">${icon('route')}</span><div class="grow"><div class="list-title mono">${listen.key}</div><div class="list-sub mono">${listen.action}</div></div></div>`)}</div>`,
            }) : ''}

            ${card({
                title: 'Everything recorded',
                flush: true,
                body: html`<div class="list" style="padding:0 24px">${result.entries.map((entry) => {
                    const d = describe(entry);

                    return html`<a class="list-item" href="${entryHref(entry)}"><span class="type-icon ${d.tone}">${icon(d.icon)}</span><div class="grow" style="min-width:0"><div class="list-title truncate">${d.title}</div><div class="list-sub truncate">${TYPES[entry.type]?.single} ${d.sub ? `· ${d.sub}` : ''}</div></div>${d.badge}</a>`;
                })}</div>`,
            })}

            <details class="card"><summary class="card-header" style="cursor:pointer;padding-bottom:20px"><h3 class="card-title">Update JSON sent</h3></summary><div class="card-body">${json(result.update)}</div></details>
            ${result.output ? html`<details class="card"><summary class="card-header" style="cursor:pointer;padding-bottom:20px"><h3 class="card-title">Process output</h3></summary><div class="card-body"><pre class="code wrap">${result.output}</pre></div></details>` : ''}`;
    }
}
