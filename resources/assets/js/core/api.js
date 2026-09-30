const config = window.Sentinel || {};

export class ApiError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

function token() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function request(method, path, { query, body } = {}) {
    const url = new URL(`${config.path}/api/${path.replace(/^\//, '')}`, window.location.origin);

    Object.entries(query || {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, value);
        }
    });

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    let data = null;

    try {
        data = await response.json();
    } catch (e) {
        data = null;
    }

    if (!response.ok) {
        const message = data?.message || data?.description || `Request failed with status ${response.status}`;

        throw new ApiError(message, response.status, data);
    }

    return data;
}

export const api = {
    get: (path, query) => request('GET', path, { query }),
    post: (path, body) => request('POST', path, { body: body || {} }),
    delete: (path, body) => request('DELETE', path, { body: body || {} }),
};
