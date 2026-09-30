/**
 * A string of trusted HTML. Everything else interpolated into html`` is escaped.
 */
export class Safe {
    constructor(value) {
        this.value = value;
    }

    toString() {
        return this.value;
    }
}

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };

export function esc(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value).replace(/[&<>"'`]/g, (char) => ESCAPES[char]);
}

export function raw(value) {
    return new Safe(value === null || value === undefined ? '' : String(value));
}

function interpolate(value) {
    if (value === null || value === undefined || value === false || value === true) {
        return '';
    }

    if (value instanceof Safe) {
        return value.value;
    }

    if (Array.isArray(value)) {
        return value.map(interpolate).join('');
    }

    return esc(value);
}

/**
 * Tagged template that escapes every interpolated value.
 */
export function html(strings, ...values) {
    let out = strings[0];

    for (let i = 0; i < values.length; i++) {
        out += interpolate(values[i]) + strings[i + 1];
    }

    return new Safe(out);
}

/**
 * Join class names, skipping falsy ones.
 */
export function cls(...names) {
    return names.flat().filter(Boolean).join(' ');
}

/**
 * Listen for an event on elements matching a selector inside a root.
 */
export function on(root, event, selector, handler) {
    const listener = (e) => {
        const target = e.target.closest(selector);

        if (target && root.contains(target)) {
            handler(e, target);
        }
    };

    root.addEventListener(event, listener);

    return () => root.removeEventListener(event, listener);
}

export function qs(selector, root = document) {
    return root.querySelector(selector);
}

export function qsa(selector, root = document) {
    return [...root.querySelectorAll(selector)];
}

export async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (e) {
        const area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}
