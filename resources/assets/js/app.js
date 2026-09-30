import { html, cls, on } from './core/dom.js';
import { icon, logo } from './core/icons.js';
import { api } from './core/api.js';
import { store, setTheme } from './core/store.js';
import { Router, navigate } from './core/router.js';
import * as fmt from './core/format.js';
import { toast, avatar } from './components/ui.js';

import OverviewPage from './pages/overview.js';
import { BotsPage, BotPage } from './pages/bots.js';
import StreamPage from './pages/stream.js';
import EntryPage from './pages/entry.js';
import ApiPage from './pages/api.js';
import AudiencePage from './pages/audience.js';
import ListenersPage from './pages/listeners.js';
import ConversationsPage from './pages/conversations.js';
import ExceptionsPage from './pages/exceptions.js';
import PerformancePage from './pages/performance.js';
import ServersPage from './pages/servers.js';
import PlaygroundPage from './pages/playground.js';
import SettingsPage from './pages/settings.js';

const config = window.Sentinel || {};

const NAV = [
    ['Menu', [
        { key: 'dashboard', label: 'Dashboard', href: '#/', icon: 'dashboard' },
        { key: 'bots', label: 'Bots & Webhooks', href: '#/bots', icon: 'webhook' },
        config.playground ? { key: 'playground', label: 'Playground', href: '#/playground', icon: 'play' } : null,
    ]],
    ['Telegram', [
        { key: 'update', label: 'Updates', href: '#/entries/update', icon: 'inbox', count: 'update' },
        { key: 'api', label: 'API Calls', href: '#/api', icon: 'send', count: 'api_call' },
        { key: 'users', label: 'Users', href: '#/audience/user', icon: 'users' },
        { key: 'chats', label: 'Chats', href: '#/audience/chat', icon: 'chats' },
        { key: 'listeners', label: 'Listeners', href: '#/listeners', icon: 'route' },
        { key: 'conversations', label: 'Conversations', href: '#/conversations', icon: 'conversation', count: 'conversation' },
    ]],
    ['Debug', [
        { key: 'exceptions', label: 'Exceptions', href: '#/exceptions', icon: 'bug', count: 'exception', alert: true },
        { key: 'log', label: 'Logs', href: '#/entries/log', icon: 'log', count: 'log' },
        { key: 'query', label: 'Queries', href: '#/entries/query', icon: 'database', count: 'query' },
        { key: 'job', label: 'Jobs', href: '#/entries/job', icon: 'layers', count: 'job' },
        { key: 'cache', label: 'Cache', href: '#/entries/cache', icon: 'zap', count: 'cache' },
        { key: 'request', label: 'HTTP Requests', href: '#/entries/request', icon: 'globe', count: 'request' },
        { key: 'command', label: 'Commands', href: '#/entries/command', icon: 'terminal', count: 'command' },
        { key: 'schedule', label: 'Schedule', href: '#/entries/schedule', icon: 'clock', count: 'schedule' },
        { key: 'event', label: 'Events', href: '#/entries/event', icon: 'radio', count: 'event', hideEmpty: true },
        { key: 'alert', label: 'Alerts', href: '#/entries/alert', icon: 'bell', count: 'alert', hideEmpty: true },
    ]],
    ['System', [
        { key: 'performance', label: 'Performance', href: '#/performance', icon: 'activity' },
        { key: 'servers', label: 'Servers & Network', href: '#/servers', icon: 'server' },
        { key: 'settings', label: 'Settings', href: '#/settings', icon: 'settings' },
    ]],
];

const ROUTES = [
    ['/', OverviewPage, 'dashboard'],
    ['/bots', BotsPage, 'bots'],
    ['/bots/:connection', BotPage, 'bots'],
    ['/playground', PlaygroundPage, 'playground'],
    ['/entries', StreamPage, 'entries'],
    ['/entries/:type', StreamPage, (params) => params.type],
    ['/entries/:type/:id', EntryPage, (params) => ({ api_call: 'api', exception: 'exceptions', conversation: 'conversations' }[params.type] || params.type)],
    ['/api', ApiPage, 'api'],
    ['/audience/:kind', AudiencePage, (params) => (params.kind === 'chat' ? 'chats' : 'users')],
    ['/listeners', ListenersPage, 'listeners'],
    ['/conversations', ConversationsPage, 'conversations'],
    ['/exceptions', ExceptionsPage, 'exceptions'],
    ['/performance', PerformancePage, 'performance'],
    ['/servers', ServersPage, 'servers'],
    ['/settings', SettingsPage, 'settings'],
];

class App {
    constructor(root) {
        this.root = root;
        this.page = null;
        this.active = 'dashboard';
        this.counts = {};
        this.menuOpen = false;

        this.renderShell();
        this.bind();

        this.router = new Router(ROUTES, (route, params, query) => this.open(route, params, query));
        this.router.resolve();

        this.loadStatus();
        setInterval(() => this.loadStatus(), 20000);
        setInterval(() => this.tick(), 5000);

        store.subscribe((keys) => {
            if (keys.includes('connection') || keys.includes('live') || keys.includes('recording') || keys.includes('theme')) {
                this.renderHeader();
                this.renderFooter();
            }

            this.page?.changed(keys);
        });
    }

    renderShell() {
        this.root.innerHTML = String(html`
            <div class="app">
                <aside class="sidebar">
                    <a class="sidebar-brand" href="#/">
                        ${logo()}
                        <span class="logo-text"><strong>Sentinel</strong><span>for LaraGram</span></span>
                    </a>
                    <nav class="sidebar-nav" id="sentinel-nav"></nav>
                    <div class="sidebar-footer" id="sentinel-footer"></div>
                </aside>
                <div class="overlay" data-action="close-sidebar"></div>
                <div class="main">
                    <header class="header" id="sentinel-header"></header>
                    <main class="content" id="sentinel-view"></main>
                </div>
            </div>`);

        this.view = this.root.querySelector('#sentinel-view');

        this.renderNav();
        this.renderHeader();
        this.renderFooter();
    }

    renderNav() {
        this.root.querySelector('#sentinel-nav').innerHTML = String(html`${NAV.map(([label, items]) => html`
            <div class="nav-group">
                <span class="nav-label">${label}</span>
                ${items.filter(Boolean).filter((item) => !item.hideEmpty || this.counts[item.count]).map((item) => {
                    const count = item.count ? this.counts[item.count] : null;

                    return html`
                        <a class="${cls('nav-item', this.active === item.key && 'active')}" href="${item.href}" title="${item.label}">
                            ${icon(item.icon)}
                            <span>${item.label}</span>
                            ${count ? html`<span class="${cls('nav-count', item.alert && 'alert')}">${fmt.compact(count)}</span>` : ''}
                        </a>`;
                })}
            </div>`)}`);
    }

    renderHeader() {
        const connections = config.connections || [];
        const current = store.get('connection');
        const currentBot = connections.find((c) => c.name === current);
        const live = store.get('live');
        const dark = store.get('theme') === 'dark';

        this.root.querySelector('#sentinel-header').innerHTML = String(html`
            <button class="header-toggle" data-action="toggle-sidebar" aria-label="Toggle sidebar">${icon('menu')}</button>
            <form class="search" data-action="search">
                ${icon('search')}
                <input type="search" name="q" placeholder="Search tags like chat:123, user:42, method:sendMessage…" autocomplete="off">
                <kbd>/</kbd>
            </form>
            <div class="header-spacer"></div>
            <div class="header-actions">
                ${connections.length > 1 ? html`
                    <div class="dropdown">
                        <button class="connection-button" data-action="toggle-connections">
                            ${currentBot ? avatar(currentBot.username || currentBot.name, currentBot.name, 'sm') : html`<span class="avatar sm" style="background:linear-gradient(135deg,#2aabee,#465fff)">${icon('bot')}</span>`}
                            <span class="hide-sm">${currentBot ? currentBot.name : 'All bots'}</span>
                            ${icon('chevronDown')}
                        </button>
                        ${this.menuOpen ? html`
                            <div class="dropdown-menu">
                                <button class="${cls('dropdown-item', current === 'all' && 'active')}" data-action="connection" data-value="all">${icon('layers')} All bots</button>
                                ${connections.map((c) => html`<button class="${cls('dropdown-item', current === c.name && 'active')}" data-action="connection" data-value="${c.name}">${icon('bot')} ${c.name}${c.username ? html` <span class="faint">@${c.username}</span>` : ''}</button>`)}
                            </div>` : ''}
                    </div>` : ''}
                <button class="${cls('icon-button', live && 'on')}" data-action="toggle-live" title="${live ? 'Live updates on' : 'Live updates paused'}">
                    ${icon(live ? 'radio' : 'pause')}${live ? html`<span class="dot"></span>` : ''}
                </button>
                <button class="icon-button" data-action="toggle-theme" title="Toggle dark mode">${icon(dark ? 'sun' : 'moon')}</button>
            </div>`);
    }

    renderFooter() {
        const recording = store.get('recording');

        this.root.querySelector('#sentinel-footer').innerHTML = String(html`
            <button class="${cls('recorder', !recording && 'paused')}" data-action="toggle-recording" title="${recording ? 'Pause recording' : 'Resume recording'}">
                <span class="recorder-dot"></span>
                <span class="recorder-text">
                    <strong>${recording ? 'Recording' : 'Paused'}</strong>
                    <span>${config.app} · v${config.version}</span>
                </span>
            </button>`);
    }

    bind() {
        on(document, 'click', '[data-action]', async (e, target) => {
            const action = target.dataset.action;

            switch (action) {
                case 'period':
                    store.set({ period: Number(target.dataset.value) });
                    this.root.querySelectorAll('[data-action=period]').forEach((button) => button.classList.toggle('active', button.dataset.value === target.dataset.value));
                    break;
                case 'toggle-theme':
                    setTheme(store.get('theme') === 'dark' ? 'light' : 'dark');
                    break;
                case 'toggle-live':
                    store.set({ live: !store.get('live') });
                    toast(store.get('live') ? 'Live updates on' : 'Live updates paused', store.get('live') ? 'Pages refresh every few seconds.' : '', 'info');
                    break;
                case 'toggle-sidebar':
                    if (window.innerWidth < 1024) {
                        document.documentElement.classList.toggle('sidebar-open');
                    } else {
                        const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
                        try { localStorage.setItem('sentinel.sidebar', collapsed ? 'collapsed' : 'open'); } catch (err) { /* ignore */ }
                    }
                    break;
                case 'close-sidebar':
                    document.documentElement.classList.remove('sidebar-open');
                    break;
                case 'toggle-connections':
                    this.menuOpen = !this.menuOpen;
                    this.renderHeader();
                    break;
                case 'connection':
                    this.menuOpen = false;
                    store.set({ connection: target.dataset.value });
                    this.renderHeader();
                    break;
                case 'toggle-recording':
                    try {
                        const result = await api.post('recording');
                        store.set({ recording: result.recording });
                        toast(result.recording ? 'Recording resumed' : 'Recording paused', result.recording ? '' : 'New updates are not recorded until you resume.', 'info');
                    } catch (error) {
                        toast('Could not change recording', error.message, 'error');
                    }
                    break;
                default:
            }
        });

        document.addEventListener('click', (e) => {
            if (this.menuOpen && !e.target.closest('.dropdown')) {
                this.menuOpen = false;
                this.renderHeader();
            }
        });

        on(document, 'submit', 'form[data-action=search]', (e, form) => {
            e.preventDefault();

            const value = form.q.value.trim();

            if (!value) return;

            if (/^[\w-]+:\S+$/.test(value) && !value.startsWith('http')) {
                navigate('/entries', { tag: value });
            } else {
                navigate('/entries', { q: value });
            }

            form.q.blur();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && !e.target.closest('input, textarea, select')) {
                e.preventDefault();
                this.root.querySelector('.search input')?.focus();
            }
        });
    }

    open(route, params, query) {
        this.page?.destroy();

        document.documentElement.classList.remove('sidebar-open');

        this.active = typeof route.nav === 'function' ? route.nav(params) : route.nav;
        this.renderNav();

        const element = document.createElement('div');
        this.view.replaceChildren(element);

        this.page = new route.handler(element, params, query);
        document.title = `${this.page.title()} · Sentinel`;
        window.scrollTo(0, 0);

        this.page.render();
    }

    tick() {
        if (!store.get('live') || document.hidden || !this.page || !this.page.constructor.live) {
            return;
        }

        this.page.refresh();
    }

    async loadStatus() {
        try {
            const status = await api.get('status');

            this.counts = status.counts || {};
            store.set({ recording: status.recording });
            this.renderNav();
        } catch (error) {
            // The next attempt may work.
        }
    }
}

new App(document.getElementById('sentinel'));
