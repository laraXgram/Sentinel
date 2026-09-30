const compactFormatter = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const numberFormatter = new Intl.NumberFormat('en');

export function number(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    return numberFormatter.format(Math.round(Number(value) * 100) / 100);
}

export function compact(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    const n = Number(value);

    return Math.abs(n) < 10000 ? numberFormatter.format(Math.round(n * 10) / 10) : compactFormatter.format(n);
}

export function duration(ms) {
    if (ms === null || ms === undefined || ms === '' || Number.isNaN(Number(ms))) {
        return '—';
    }

    const n = Number(ms);

    if (n === 0) {
        return '0ms';
    }

    if (n < 1) {
        return `${n.toFixed(2)}ms`;
    }

    if (n < 1000) {
        return `${Math.round(n)}ms`;
    }

    if (n < 60000) {
        return `${(n / 1000).toFixed(n < 10000 ? 2 : 1)}s`;
    }

    return `${Math.floor(n / 60000)}m ${Math.round((n % 60000) / 1000)}s`;
}

export function seconds(value) {
    const n = Number(value) || 0;

    if (n < 60) return `${n}s`;
    if (n < 3600) return `${Math.floor(n / 60)}m`;
    if (n < 86400) return `${Math.floor(n / 3600)}h ${Math.floor((n % 3600) / 60)}m`;

    return `${Math.floor(n / 86400)}d ${Math.floor((n % 86400) / 3600)}h`;
}

export function ago(timestamp) {
    if (!timestamp) {
        return '—';
    }

    const time = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp);
    const diff = Math.max(0, (Date.now() - time) / 1000);

    if (diff < 5) return 'just now';
    if (diff < 60) return `${Math.floor(diff)}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 86400 * 30) return `${Math.floor(diff / 86400)}d ago`;

    return dateTime(timestamp);
}

export function dateTime(timestamp) {
    if (!timestamp) {
        return '—';
    }

    const date = new Date(typeof timestamp === 'number' ? timestamp * 1000 : timestamp);

    return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

export function clock(timestamp) {
    const date = new Date(typeof timestamp === 'number' ? timestamp * 1000 : timestamp);

    return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

/**
 * Label a chart bucket for the given period (in minutes).
 */
export function bucketLabel(timestamp, period) {
    const date = new Date(timestamp * 1000);

    if (period >= 10080) {
        return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
    }

    return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

export function bucketTitle(timestamp, period) {
    const start = new Date(timestamp * 1000);
    const end = new Date((timestamp + period) * 1000);
    const day = start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    const time = (date) => date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

    return `${day}, ${time(start)} – ${time(end)}`;
}

export function percent(value, digits = 1) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    return `${Number(value).toFixed(digits).replace(/\.0+$/, '')}%`;
}

export function delta(current, previous) {
    current = Number(current) || 0;
    previous = Number(previous) || 0;

    if (previous === 0) {
        return current === 0 ? 0 : null;
    }

    return ((current - previous) / previous) * 100;
}

export function bytes(megabytes) {
    const n = Number(megabytes) || 0;

    return n >= 1024 ? `${(n / 1024).toFixed(1)} GB` : `${Math.round(n)} MB`;
}

export function plural(count, word) {
    if (Number(count) === 1) {
        return `${number(count)} ${word}`;
    }

    const plural = /[^aeiou]y$/.test(word) ? `${word.slice(0, -1)}ies` : /(s|x|ch|sh)$/.test(word) ? `${word}es` : `${word}s`;

    return `${number(count)} ${plural}`;
}

export function title(value) {
    return String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export function periodName(period) {
    return { 60: 'last hour', 360: 'last 6 hours', 1440: 'last 24 hours', 10080: 'last 7 days' }[period] || 'last hour';
}

export function truncate(value, length = 80) {
    const text = String(value ?? '');

    return text.length > length ? `${text.slice(0, length - 1)}…` : text;
}
