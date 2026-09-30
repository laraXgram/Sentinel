import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { raw } from '../core/dom.js';
import * as fmt from '../core/format.js';
import { card, pageHead, props, json, codeLines, code, sql, empty, avatar, toast, skeleton, badge } from '../components/ui.js';
import { TYPES, describe, entryHref } from '../components/entries.js';
import { chatPreview, personName, statusBadge, updateMeta } from '../components/telegram.js';

export default class EntryPage extends Page {
    setup() {
        this.replay = null;

        this.on('click', '[data-action=replay]', async (e, button) => {
            const mode = button.dataset.mode;

            button.classList.add('loading');

            try {
                this.replay = await api.post(`entries/${this.params.id}/replay`, { mode });
                toast(mode === 'live' ? 'Update replayed live' : 'Dry run finished', `${fmt.plural(this.replay.entries.length, 'entry')} recorded in ${fmt.duration(this.replay.duration)}.`);
            } catch (error) {
                toast('Replay failed', error.message, 'error');
            }

            this.paint();
            this.el.querySelector('#replay')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        this.on('click', '[data-action=toggle-vendor]', () => {
            this.showVendor = !this.showVendor;
            this.paint();
        });
    }

    title() {
        return TYPES[this.params.type]?.single || 'Entry';
    }

    loading() {
        return skeleton({ tiles: 0, blocks: 2 });
    }

    load() {
        return api.get(`entries/${this.params.id}`);
    }

    view(data) {
        const { entry, batch } = data;
        const type = TYPES[entry.type] || { label: 'Entries', single: 'Entry' };
        const d = describe(entry);

        return html`
            ${pageHead({
                crumbs: [{ label: type.label, href: `#/entries/${entry.type}` }, { label: fmt.truncate(d.title, 48) }],
                title: fmt.truncate(d.title, 90),
                sub: html`${type.single} · ${fmt.dateTime(entry.created_at)} (${fmt.ago(entry.timestamp)})${entry.connection ? ` · connection: ${entry.connection}` : ''}`,
                tools: html`${d.badge}${entry.type === 'update' && window.Sentinel.playground ? html`
                    <button class="btn outline sm" data-action="replay" data-mode="dry" title="Run this update through your bot again; every API call is answered locally">${icon('rewind')} Replay (dry run)</button>
                    ${window.Sentinel.allow_live ? html`<button class="btn danger-outline sm" data-action="replay" data-mode="live" title="Really send the API calls again">${icon('send')} Replay live</button>` : ''}` : ''}`,
            })}

            <div class="grid cols-12">
                <div class="span-8 stack">
                    ${this.replay ? this.replayCard() : ''}
                    ${this.main(entry, batch, data)}
                </div>
                <div class="span-4 stack">
                    ${this.side(entry, batch, data)}
                    ${entry.tags.length ? card({
                        title: 'Tags',
                        body: html`<div class="chips">${entry.tags.map((tag) => html`<a class="tag" href="#/entries?tag=${encodeURIComponent(tag)}">${tag}</a>`)}</div>`,
                    }) : ''}
                    ${this.timeline(entry, batch)}
                </div>
            </div>`;
    }

    replayCard() {
        const replay = this.replay;
        const update = replay.entries.find((e) => e.type === 'update');
        const calls = replay.entries.filter((e) => e.type === 'api_call');
        const exceptions = replay.entries.filter((e) => e.type === 'exception');

        return html`<div id="replay">${card({
            title: replay.mode === 'live' ? 'Live replay' : 'Dry-run replay',
            sub: `${fmt.duration(replay.duration)} · exit code ${replay.exit_code ?? '—'} · ${fmt.plural(calls.length, 'API call')}`,
            className: 'accent',
            actions: update ? html`<a class="btn xs outline" href="${entryHref(update)}">Open result</a>` : '',
            body: html`
                ${exceptions.map((exception) => html`<div class="issue error mb-4">${icon('bug')}<div><div class="issue-title">${exception.content.class}</div><div class="issue-detail">${exception.content.message}</div></div></div>`)}
                ${chatPreview({ update: replay.update, summary: update?.content, calls })}
                ${replay.output ? html`<details class="mt-4"><summary class="muted small" style="cursor:pointer">Process output</summary>${code(replay.output, true)}</details>` : ''}`,
        })}</div>`;
    }

    main(entry, batch, data) {
        const c = entry.content;

        switch (entry.type) {
            case 'update': {
                const calls = batch.filter((e) => e.type === 'api_call');

                return html`
                    ${card({ title: 'Conversation', sub: 'What the user sent and what the bot answered', body: chatPreview({ update: c.update, summary: c, calls }) })}
                    ${card({
                        title: 'Handled by',
                        flush: !!c.listens?.length,
                        body: c.listens?.length ? html`
                            <div class="table-wrap"><table class="table">
                                <thead><tr><th>Listen</th><th>Action</th><th>Middleware</th></tr></thead>
                                <tbody>${c.listens.map((listen) => html`
                                    <tr>
                                        <td><div class="cell-main mono">${listen.key}</div>${listen.name ? html`<div class="cell-sub">${listen.name}</div>` : ''}</td>
                                        <td class="mono small">${listen.action}</td>
                                        <td>${(listen.middleware || []).map((m) => html`<span class="tag">${m.split('\\').pop()}</span> `)}</td>
                                    </tr>`)}</tbody>
                            </table></div>` : empty({ compact: true, iconName: 'route', title: 'No listen matched this update', text: 'Add a listener for it, or ignore this update type in your allowed_updates.' }),
                    })}
                    ${c.response ? card({ title: 'Response', body: code(c.response, true) }) : ''}
                    ${card({ title: 'Raw update', sub: 'Exactly what Telegram delivered (sensitive values masked)', body: json(c.update) })}`;
            }

            case 'api_call':
                return html`
                    ${c.ok === false ? html`<div class="issue error">${icon('alertCircle')}<div><div class="issue-title">Telegram refused the call · ${c.error_code}</div><div class="issue-detail">${c.description}${c.retry_after ? html` — retry after <b>${c.retry_after}s</b>` : ''}${c.migrate_to_chat_id ? html` — the group moved to <b>${c.migrate_to_chat_id}</b>` : ''}</div></div></div>` : ''}
                    ${c.intercepted ? html`<div class="callout">${icon('info')}<div>This call was made during a <b>dry run</b>. Sentinel answered it locally; Telegram never received it.</div></div>` : ''}
                    ${card({ title: 'Parameters', body: json(c.parameters) })}
                    ${card({ title: 'Result', body: typeof c.result === 'string' ? code(c.result, true) : json(c.result) })}`;

            case 'exception':
                return html`
                    <div class="issue error">${icon('bug')}<div><div class="issue-title">${c.class}</div><div class="issue-detail" style="font-size:14px;color:var(--text)">${c.message}</div></div></div>
                    ${c.line_preview && Object.keys(c.line_preview).length ? card({ title: `${c.file}:${c.line}`, body: codeLines(c.line_preview, c.line) }) : ''}
                    ${card({
                        title: 'Stack trace',
                        actions: html`<button class="btn xs outline" data-action="toggle-vendor">${this.showVendor ? 'Hide' : 'Show'} vendor frames</button>`,
                        flush: true,
                        body: this.trace(c.trace || []),
                    })}
                    ${c.previous?.length ? card({ title: 'Previous exceptions', body: html`${c.previous.map((p) => html`<div class="issue">${icon('alertTriangle')}<div><div class="issue-title">${p.class}</div><div class="issue-detail">${p.message} · ${p.file}:${p.line}</div></div></div>`)}` }) : ''}
                    ${c.context ? card({ title: 'Context', body: json(c.context) }) : ''}
                    ${data.family?.length > 1 ? card({
                        title: 'Recent occurrences',
                        flush: true,
                        body: html`<div class="list" style="padding:0 24px">${data.family.map((occurrence) => html`
                            <a class="list-item" href="${entryHref(occurrence)}">
                                <span class="status-dot ${occurrence.id === entry.id ? 'error' : ''}"></span>
                                <span class="grow truncate">${occurrence.content.message}</span>
                                <span class="muted small">${fmt.ago(occurrence.timestamp)}</span>
                            </a>`)}</div>`,
                    }) : ''}`;

            case 'conversation':
                return html`
                    ${c.question ? card({ title: 'Question', body: props([['Name', c.question.name], ['Type', c.question.type], ['Prompt', c.question.prompt], ['Rules', c.question.rules ? JSON.stringify(c.question.rules) : null]]) }) : ''}
                    ${c.answer ? card({ title: 'Answer', body: json(c.answer) }) : ''}
                    ${c.errors ? card({ title: 'Validation errors', body: json(c.errors) }) : ''}
                    ${c.answers ? card({ title: 'Collected answers', body: json(c.answers) }) : ''}
                    ${c.reason ? card({ title: 'Cancelled because', body: code(c.reason, true) }) : ''}`;

            case 'log':
                return html`${card({ title: 'Message', body: code(c.message, true) })}${Object.keys(c.context || {}).length ? card({ title: 'Context', body: json(c.context) }) : ''}`;

            case 'query':
                return card({ title: 'Query', actions: html`<button class="btn xs outline" data-action="copy" data-value="${c.sql}">${icon('copy')} Copy</button>`, body: code(sql(c.sql), true) });

            case 'job':
                return html`
                    ${c.exception ? html`<div class="issue error">${icon('bug')}<div><div class="issue-title">${c.exception.class}</div><div class="issue-detail">${c.exception.message} · ${c.exception.file}:${c.exception.line}</div></div></div>` : ''}
                    ${c.data ? card({ title: 'Data', body: json(c.data) }) : ''}
                    ${c.exception?.trace ? card({ title: 'Stack trace', flush: true, body: this.trace(c.exception.trace) }) : ''}`;

            case 'cache':
                return card({ title: 'Value', body: c.value !== undefined ? json(c.value) : empty({ compact: true, title: 'No value for this operation' }) });

            case 'command':
                return html`${card({ title: 'Arguments', body: json(c.arguments) })}${card({ title: 'Options', body: json(c.options) })}`;

            case 'request':
                return html`
                    ${card({ title: 'Payload', body: json(c.payload) })}
                    ${card({ title: 'Headers', body: json(c.headers) })}
                    ${card({ title: 'Response', body: typeof c.response === 'string' ? code(c.response, true) : json(c.response) })}`;

            case 'event':
                return card({ title: 'Payload', body: json(c.payload) });

            case 'alert':
                return card({ title: 'Message', body: code(c.message, true) });

            case 'schedule':
                return c.exception ? html`<div class="issue error">${icon('bug')}<div><div class="issue-title">Task failed</div><div class="issue-detail">${c.exception}</div></div></div>` : card({ title: 'Task', body: code(c.command, true) });

            default:
                return card({ title: 'Content', body: json(c) });
        }
    }

    trace(frames) {
        const visible = this.showVendor ? frames : frames.filter((frame, index) => index === 0 || !String(frame.file || '').includes('vendor/') && !String(frame.file || '').startsWith('/'));
        const hidden = frames.length - visible.length;

        return html`
            <div class="table-wrap"><table class="table compact">
                <tbody>${visible.map((frame) => html`
                    <tr>
                        <td style="width:40%"><span class="mono small">${frame.class ? `${frame.class.split('\\').pop()}${frame.type || '::'}` : ''}${frame.function || ''}${frame.function ? '()' : ''}</span></td>
                        <td class="mono small muted">${frame.file || '[internal]'}${frame.line ? `:${frame.line}` : ''}</td>
                    </tr>`)}</tbody>
            </table></div>
            ${hidden > 0 ? html`<div class="card-footer">${fmt.plural(hidden, 'vendor frame')} hidden</div>` : ''}`;
    }

    side(entry, batch) {
        const c = entry.content;

        switch (entry.type) {
            case 'update': {
                const user = c.user;
                const chat = c.chat;

                return html`
                    ${card({
                        title: 'Summary',
                        body: props([
                            ['Status', statusBadge(c.status)],
                            ['Type', updateMeta(c.type).label],
                            ['Content', fmt.title(c.kind)],
                            ['Update ID', html`<span class="mono">${c.update_id}</span>`],
                            ['Handled in', html`<b>${fmt.duration(c.duration)}</b>`],
                            ['Telegram API', `${fmt.plural(c.api_calls, 'call')} · ${fmt.duration(c.api_time)}${c.api_errors ? ` · ${c.api_errors} failed` : ''}`],
                            ['Queries', `${fmt.number(c.queries)} · ${fmt.duration(c.query_time)}`],
                            ['Peak memory', `${c.memory} MB`],
                            ['Bot', entry.connection],
                            c.simulation ? ['Simulation', badge(c.simulation.mode === 'live' ? 'live replay' : 'dry run', 'brand')] : null,
                        ]),
                    })}
                    ${user ? card({
                        title: 'From',
                        body: html`
                            <div class="row" style="gap:14px">
                                ${avatar(personName(user), user.id, 'lg')}
                                <div class="grow" style="min-width:0">
                                    <div class="strong truncate">${personName(user)}${user.is_premium ? html` <span title="Telegram Premium">⭐</span>` : ''}</div>
                                    <div class="muted small">${user.username ? `@${user.username} · ` : ''}<span class="mono">${user.id}</span>${user.language_code ? ` · ${user.language_code}` : ''}</div>
                                </div>
                            </div>
                            <div class="row wrap mt-4">
                                <a class="btn xs outline" href="#/entries?tag=user:${user.id}">${icon('list')} Everything from this user</a>
                                ${user.username ? html`<a class="btn xs ghost" href="https://t.me/${user.username}" target="_blank" rel="noopener">${icon('external')} t.me</a>` : ''}
                            </div>`,
                    }) : ''}
                    ${chat && chat.id !== user?.id ? card({
                        title: 'Chat',
                        body: html`
                            <div class="row" style="gap:14px">
                                ${avatar(personName(chat), chat.id, 'lg', true)}
                                <div class="grow" style="min-width:0">
                                    <div class="strong truncate">${personName(chat)}</div>
                                    <div class="muted small">${fmt.title(chat.type)} · <span class="mono">${chat.id}</span></div>
                                </div>
                            </div>
                            <a class="btn xs outline mt-4" href="#/entries?tag=chat:${chat.id}">${icon('list')} Everything in this chat</a>`,
                    }) : ''}`;
            }

            case 'api_call':
                return card({
                    title: 'Details',
                    body: props([
                        ['Method', html`<span class="mono">${c.method}</span>`],
                        ['Result', c.ok === false ? badge(`failed · ${c.error_code}`, 'error') : c.intercepted ? badge('dry run', 'brand') : badge('ok', 'success')],
                        ['Duration', fmt.duration(c.duration)],
                        ['Bot', entry.connection],
                        ['Called from', c.caller ? html`<span class="mono small">${c.caller}</span>` : null],
                        ['Retry after', c.retry_after ? `${c.retry_after}s` : null],
                        ['Exception', c.exception],
                        ['Network error', c.network_error ? 'yes' : null],
                    ]),
                });

            case 'exception':
                return card({
                    title: 'Details',
                    body: props([
                        ['Class', html`<span class="mono small">${c.class}</span>`],
                        ['Location', html`<span class="mono small">${c.file}:${c.line}</span>`],
                        ['Code', c.code || null],
                        ['While handling', c.update_type ? updateMeta(c.update_type).label : null],
                        ['Host', c.hostname],
                    ]),
                });

            default: {
                const rows = Object.entries(c)
                    .filter(([key, value]) => value !== null && typeof value !== 'object' && !['sql', 'message'].includes(key))
                    .map(([key, value]) => [fmt.title(key), String(value)]);

                return card({ title: 'Details', body: props(rows) });
            }
        }
    }

    timeline(entry, batch) {
        const items = [entry, ...batch].sort((a, b) => a.sequence - b.sequence);

        if (batch.length === 0) {
            return '';
        }

        const counts = batch.reduce((acc, e) => ({ ...acc, [e.type]: (acc[e.type] || 0) + 1 }), {});

        return card({
            title: 'Timeline',
            sub: `${fmt.plural(batch.length + 1, 'entry')} recorded together: ${Object.entries(counts).map(([type, count]) => (count === 1 ? `1 ${(TYPES[type]?.single || type).toLowerCase()}` : `${count} ${(TYPES[type]?.label || type).toLowerCase()}`)).join(', ')}`,
            body: html`
                <div class="timeline">
                    ${items.map((item) => {
                        const d = describe(item);
                        const current = item.id === entry.id;

                        return html`
                            <a class="timeline-item" href="${entryHref(item)}" style="${current ? raw('pointer-events:none') : ''}">
                                <span class="type-icon ${d.tone}" style="${current ? raw('box-shadow:0 0 0 3px var(--brand-200)') : ''}">${icon(d.icon)}</span>
                                <div class="timeline-body">
                                    <div class="timeline-title"><span class="grow">${fmt.truncate(d.title, 60)}</span>${d.duration ? html`<span class="small muted nowrap">${fmt.duration(d.duration)}</span>` : ''}</div>
                                    <div class="timeline-sub">${TYPES[item.type]?.single || item.type}${d.sub ? ` · ${d.sub}` : ''}</div>
                                </div>
                            </a>`;
                    })}
                </div>`,
        });
    }
}
