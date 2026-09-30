/**
 * A tiny hash router: "#/entries/update/abc?tag=x" -> route + params + query.
 */
export class Router {
    constructor(routes, onChange) {
        this.routes = routes.map(([pattern, handler, nav]) => ({
            keys: [...pattern.matchAll(/:(\w+)/g)].map((match) => match[1]),
            regex: new RegExp(`^${pattern.replace(/:(\w+)/g, '([^/]+)')}/?$`),
            handler,
            nav,
        }));
        this.onChange = onChange;

        window.addEventListener('hashchange', () => this.resolve());
    }

    resolve() {
        const hash = window.location.hash.replace(/^#/, '') || '/';
        const [path, search = ''] = hash.split('?');
        const query = Object.fromEntries(new URLSearchParams(search));

        for (const route of this.routes) {
            const match = path.match(route.regex);

            if (match) {
                const params = Object.fromEntries(route.keys.map((key, index) => [key, decodeURIComponent(match[index + 1])]));

                this.onChange(route, params, query, path);

                return;
            }
        }

        window.location.hash = '#/';
    }
}

export function navigate(path, query = {}) {
    const search = new URLSearchParams(Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== '')).toString();

    window.location.hash = `#${path}${search ? `?${search}` : ''}`;
}
