const KEY = 'sentinel.state';

function read() {
    try {
        return JSON.parse(localStorage.getItem(KEY) || '{}') || {};
    } catch (e) {
        return {};
    }
}

const saved = read();

const state = {
    period: [60, 360, 1440, 10080].includes(saved.period) ? saved.period : 60,
    connection: saved.connection || 'all',
    live: saved.live !== false,
    theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    recording: true,
};

const listeners = new Set();

export const store = {
    get(key) {
        return state[key];
    },

    all() {
        return { ...state };
    },

    set(changes) {
        const changed = Object.keys(changes).filter((key) => state[key] !== changes[key]);

        if (changed.length === 0) {
            return;
        }

        Object.assign(state, changes);

        try {
            localStorage.setItem(KEY, JSON.stringify({ period: state.period, connection: state.connection, live: state.live }));
        } catch (e) {
            // Storage may be unavailable.
        }

        listeners.forEach((listener) => listener(changed, state));
    },

    subscribe(listener) {
        listeners.add(listener);

        return () => listeners.delete(listener);
    },

    /**
     * The query parameters every metric request carries.
     */
    query(extra = {}) {
        return { period: state.period, connection: state.connection === 'all' ? '' : state.connection, ...extra };
    },
};

export function setTheme(theme) {
    document.documentElement.classList.toggle('dark', theme === 'dark');

    try {
        localStorage.setItem('sentinel.theme', theme);
    } catch (e) {
        // Storage may be unavailable.
    }

    store.set({ theme });
}
