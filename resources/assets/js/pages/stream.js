import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import { navigate } from '../core/router.js';
import { cls } from '../core/dom.js';
import * as fmt from '../core/format.js';
import { pageHead, empty, toast, skeleton } from '../components/ui.js';
import { TYPES, entryRow } from '../components/entries.js';

const FILTERS = {
    update: [['', 'All'], ['unhandled', 'Unhandled'], ['failed', 'Failed'], ['slow', 'Slow'], ['kind:command', 'Commands'], ['update:callback_query', 'Callbacks'], ['update:inline_query', 'Inline'], ['update:my_chat_member', 'Membership'], ['simulated', 'Simulated']],
    api_call: [['', 'All'], ['failed', 'Failed'], ['flood', 'Flood waits'], ['blocked', 'Blocked'], ['slow', 'Slow'], ['network', 'Network'], ['intercepted', 'Dry run']],
    conversation: [['', 'All'], ['step:started', 'Started'], ['step:completed', 'Completed'], ['step:cancelled', 'Cancelled'], ['step:invalid', 'Invalid answers']],
    log: [['', 'All'], ['level:error', 'Errors'], ['level:warning', 'Warnings'], ['level:info', 'Info'], ['level:debug', 'Debug']],
    query: [['', 'All'], ['slow', 'Slow']],
    job: [['', 'All'], ['failed', 'Failed']],
    request: [['', 'All'], ['failed', 'Failed'], ['slow', 'Slow']],
    command: [['', 'All'], ['failed', 'Failed']],
    schedule: [['', 'All'], ['failed', 'Failed']],
};

const BOT_TYPES = ['update', 'api_call', 'conversation'];

const SUBS = {
    update: 'Every update Telegram delivered, what handled it and how long it took.',
    api_call: 'Every call your bot made to the Telegram Bot API.',
    conversation: 'Questions asked, answers received and where users dropped off.',
    exception: 'Exceptions thrown while handling updates, jobs and requests.',
    log: 'Messages written to your application logs.',
    query: 'Database queries and where in your code they ran.',
    job: 'Queued jobs: dispatched, processed and failed.',
    cache: 'Cache hits, misses, writes and deletes.',
    request: 'Web requests to your application (the dashboard is excluded).',
    command: 'Console commands that ran.',
    schedule: 'Scheduled tasks that ran.',
    event: 'Application events that were dispatched.',
    alert: 'Alerts Sentinel sent to your Telegram chats.',
};

export default class StreamPage extends Page {
    static live = true;

    setup() {
        this.type = this.params.type || null;
        this.entries = [];
        this.next = null;
        this.pending = [];

        this.on('submit', 'form[data-action=filter-search]', (e, form) => {
            e.preventDefault();
            navigate(this.path(), { ...this.query, q: form.q.value.trim() || undefined });
        });

        this.on('click', '[data-action=filter]', (e, button) => {
            navigate(this.path(), { ...this.query, tag: button.dataset.value || undefined });
        });

        this.on('click', '[data-action=clear-tag]', () => navigate(this.path(), { ...this.query, tag: undefined }));
        this.on('click', '[data-action=clear-q]', () => navigate(this.path(), { ...this.query, q: undefined }));

        this.on('click', '[data-action=load-more]', async (e, button) => {
            button.classList.add('loading');

            const result = await api.get(this.endpoint(), this.params_({ before: this.next }));

            this.entries.push(...result.entries);
            this.next = result.next;
            this.paint();
        });

        this.on('click', '[data-action=show-new]', () => {
            this.entries.unshift(...this.pending);
            this.flash = new Set(this.pending.map((entry) => entry.id));
            this.pending = [];
            this.paint();
        });

        this.on('click', '[data-action=monitor]', async () => {
            await api.post('monitoring', { tag: this.query.tag });
            toast('Monitoring tag', `${this.query.tag} is recorded even when filters would skip it.`);
        });
    }

    path() {
        return this.type ? `/entries/${this.type}` : '/entries';
    }

    endpoint() {
        return this.type ? `entries/type/${this.type}` : 'entries';
    }

    params_(extra = {}) {
        const connection = store.get('connection');

        return {
            tag: this.query.tag,
            q: this.query.q,
            connection: connection !== 'all' && (!this.type || BOT_TYPES.includes(this.type)) ? connection : '',
            take: 50,
            ...extra,
        };
    }

    title() {
        return this.type ? TYPES[this.type]?.label || 'Entries' : 'Search';
    }

    loading() {
        return skeleton({ tiles: 0, blocks: 1 });
    }

    async load() {
        const result = await api.get(this.endpoint(), this.params_());

        this.entries = result.entries;
        this.next = result.next;
        this.pending = [];

        return result;
    }

    async refresh() {
        if (!this.data) return;

        const result = await api.get(this.endpoint(), this.params_({ take: 30 }));
        const top = this.entries[0]?.sequence || 0;
        const known = new Set([...this.pending, ...this.entries].map((entry) => entry.id));
        const fresh = result.entries.filter((entry) => entry.sequence > top && !known.has(entry.id));

        if (!fresh.length) return;

        if (window.scrollY < 120) {
            this.entries.unshift(...fresh);
            this.flash = new Set(fresh.map((entry) => entry.id));
        } else {
            this.pending.unshift(...fresh);
        }

        this.paint();
    }

    view() {
        const type = TYPES[this.type];
        const filters = FILTERS[this.type] || null;
        const tag = this.query.tag || '';

        return html`
            ${pageHead({
                title: type ? type.label : 'Search entries',
                sub: this.type ? SUBS[this.type] : 'Entries of every type matching your search.',
                crumbs: this.type ? null : [{ label: 'Dashboard', href: '#/' }, { label: 'Search' }],
            })}

            <section class="card">
                <div class="stream-toolbar">
                    <form class="search" data-action="filter-search">
                        ${icon('search')}
                        <input type="search" name="q" value="${this.query.q || ''}" placeholder="${this.type === 'update' ? 'Search text, usernames, IDs…' : 'Search inside entries…'}">
                    </form>
                    ${filters ? html`<div class="chips">${filters.map(([value, label]) => html`<button class="${cls('chip', tag === value && 'active')}" data-action="filter" data-value="${value}">${label}</button>`)}</div>` : ''}
                </div>

                ${(tag && !(filters || []).some(([value]) => value === tag)) || this.query.q ? html`
                    <div class="stream-toolbar" style="padding-top:12px;padding-bottom:12px">
                        <span class="muted small">Filtered by</span>
                        ${tag ? html`<span class="badge brand lg">${icon('tag')}${tag}<button data-action="clear-tag" aria-label="Clear">${icon('x')}</button></span>` : ''}
                        ${this.query.q ? html`<span class="badge brand lg">${icon('search')}“${this.query.q}”<button data-action="clear-q" aria-label="Clear">${icon('x')}</button></span>` : ''}
                        <span class="grow"></span>
                        ${tag ? html`<button class="btn xs outline" data-action="monitor">${icon('eye')} Always record this tag</button>` : ''}
                    </div>` : ''}

                ${this.pending.length ? html`<div class="new-entries"><button class="btn xs primary" data-action="show-new">${icon('arrowUp')} ${fmt.plural(this.pending.length, 'new entry')}</button></div>` : ''}

                ${this.entries.length ? html`
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>${type ? type.single : 'Entry'}</th><th class="hide-sm">Status</th><th class="num hide-sm">Duration</th><th class="num">Happened</th></tr></thead>
                            <tbody>${this.entries.map((entry) => entryRow(entry, { showType: !this.type }))}</tbody>
                        </table>
                    </div>
                    ${this.next ? html`<div class="load-more"><button class="btn outline sm" data-action="load-more">${icon('arrowDown')} Load older entries</button></div>` : ''}` : empty({
                        iconName: type?.icon || 'search',
                        title: tag || this.query.q ? 'No matching entries' : `No ${type ? type.label.toLowerCase() : 'entries'} recorded yet`,
                        text: tag || this.query.q ? 'Try another filter or clear the search.' : this.type === 'update' ? 'Send your bot a message, or try the playground to fake one.' : 'Entries appear here as soon as they are recorded.',
                        action: this.type === 'update' && window.Sentinel.playground && !tag ? html`<a class="btn primary sm" href="#/playground">${icon('play')} Open playground</a>` : '',
                    })}
            </section>`;
    }

    after() {
        if (this.flash) {
            this.flash.forEach((id) => this.el.querySelector(`tr[data-href$="${id}"]`)?.classList.add('flash'));
            this.flash = null;
        }
    }
}
