import { on, copy, html } from './dom.js';
import { store } from './store.js';
import { skeleton, errorView, toast, segmented } from '../components/ui.js';
import { mountCharts } from '../components/charts.js';

/**
 * Base class of every dashboard page.
 *
 * A page loads its data, renders it to HTML and mounts its charts. Pages
 * marked `live` are refreshed in place while live mode is on.
 */
export class Page {
    static live = false;

    constructor(element, params = {}, query = {}) {
        this.el = element;
        this.params = params;
        this.query = query;
        this.data = null;
        this.destroyed = false;
        this.disposers = [];
        this.disposeCharts = null;

        this.on('click', '[data-action=retry]', () => this.render());
        this.on('click', '[data-action=copy-json]', async (e, button) => {
            const target = document.getElementById(button.dataset.target);

            if (target && await copy(JSON.stringify(JSON.parse(target.dataset.json), null, 2))) {
                toast('Copied to clipboard');
            }
        });
        this.on('click', '[data-action=copy]', async (e, button) => {
            if (await copy(button.dataset.value)) toast('Copied to clipboard');
        });
        this.on('click', 'tr[data-href]', (e, row) => {
            if (!e.target.closest('a, button, input, select')) {
                if (e.metaKey || e.ctrlKey) {
                    window.open(row.dataset.href, '_blank');
                } else {
                    window.location.hash = row.dataset.href;
                }
            }
        });

        this.setup();
    }

    /**
     * Register event handlers; called once.
     */
    setup() {}

    on(event, selector, handler) {
        this.disposers.push(on(this.el, event, selector, handler.bind(this)));
    }

    title() {
        return 'Sentinel';
    }

    async load() {
        return {};
    }

    view() {
        return '';
    }

    charts() {
        return {};
    }

    after() {}

    loading() {
        return skeleton();
    }

    async render({ soft = false } = {}) {
        if (!soft) {
            this.el.innerHTML = String(this.loading());
        }

        try {
            const data = await this.load();

            if (this.destroyed) return;

            this.data = data;
            this.paint();
        } catch (error) {
            if (this.destroyed) return;

            if (soft && this.data) {
                console.warn('Sentinel refresh failed', error);
                return;
            }

            this.el.innerHTML = String(errorView(error));
        }
    }

    paint() {
        this.disposeCharts?.();

        const open = [...this.el.querySelectorAll('details[data-keep]')].filter((d) => d.open).map((d) => d.dataset.keep);

        this.el.innerHTML = String(this.view(this.data));

        open.forEach((key) => {
            const details = this.el.querySelector(`details[data-keep="${key}"]`);
            if (details) details.open = true;
        });

        this.disposeCharts = mountCharts(this.el, this.charts(this.data));
        this.after(this.data);
    }

    refresh() {
        return this.render({ soft: true });
    }

    /**
     * React to the global period or connection changing.
     */
    changed(keys) {
        if (keys.includes('period') || keys.includes('connection')) {
            this.render({ soft: true });
        }
    }

    destroy() {
        this.destroyed = true;
        this.disposeCharts?.();
        this.disposers.forEach((dispose) => dispose());
    }

    periodPicker() {
        return segmented([[60, '1h'], [360, '6h'], [1440, '24h'], [10080, '7d']], store.get('period'), 'period');
    }
}

export { html };
