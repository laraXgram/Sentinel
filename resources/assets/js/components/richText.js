import { esc } from '../core/dom.js';

/*
 * Telegram message text, the way the Telegram apps show it.
 *
 * Every input (Telegram HTML, MarkdownV2, legacy Markdown, or plain text with
 * a MessageEntity list) is first turned into plain text plus entities, like
 * the Bot API does, and then rendered from those entities. Offsets are
 * UTF-16 code units, which is exactly how JavaScript strings index text.
 */

const SAFE_URL = /^(https?:|tg:|mailto:|tel:)/i;

/**
 * Parse text sent with the given parse_mode into text and entities.
 *
 * @returns {{ text: string, entities: Array<object> }}
 */
export function parse(text, parseMode, entities = null) {
    const source = String(text ?? '');
    const mode = String(parseMode || '').toLowerCase();

    if (mode === 'html') return parseHtml(source);
    if (mode === 'markdownv2') return parseMarkdown(source, true);
    if (mode === 'markdown') return parseMarkdown(source, false);

    return { text: source, entities: normalizeEntities(entities) };
}

/**
 * Render text plus entities as safe HTML.
 */
export function render(text, entities) {
    const list = normalizeEntities(entities)
        .map((entity) => ({ ...entity, end: entity.offset + entity.length }))
        .filter((entity) => entity.length > 0 && entity.offset >= 0 && entity.end <= text.length)
        .sort((a, b) => a.offset - b.offset || b.end - a.end);

    const root = { start: 0, end: text.length, children: [] };
    const stack = [root];

    for (const entity of list) {
        while (stack.length > 1 && entity.offset >= stack[stack.length - 1].end) {
            stack.pop();
        }

        const parent = stack[stack.length - 1];
        const node = { entity, start: entity.offset, end: Math.min(entity.end, parent.end), children: [] };

        parent.children.push(node);
        stack.push(node);
    }

    return renderNode(root, text);
}

const BLOCK_TYPES = ['pre', 'blockquote', 'expandable_blockquote'];

function renderNode(node, text) {
    let out = '';
    let position = node.start;

    for (const child of node.children) {
        const block = BLOCK_TYPES.includes(child.entity.type);
        let before = text.slice(position, child.start);

        // A block already starts and ends its own line; one newline around it is redundant.
        if (block && before.endsWith('\n')) before = before.slice(0, -1);

        out += esc(before);
        out += wrap(child.entity, renderNode(child, text), text.slice(child.start, child.end));
        position = block && text[child.end] === '\n' && child.end < node.end ? child.end + 1 : child.end;
    }

    return out + esc(text.slice(position, node.end));
}

function link(href, inner, title = '') {
    if (!SAFE_URL.test(href)) {
        return `<span class="tg-link"${title ? ` title="${esc(title)}"` : ''}>${inner}</span>`;
    }

    return `<a class="tg-link" href="${esc(href)}" target="_blank" rel="noopener noreferrer"${title ? ` title="${esc(title)}"` : ''}>${inner}</a>`;
}

function wrap(entity, inner, value) {
    switch (entity.type) {
        case 'bold': return `<b>${inner}</b>`;
        case 'italic': return `<i>${inner}</i>`;
        case 'underline': return `<u>${inner}</u>`;
        case 'strikethrough': return `<s>${inner}</s>`;
        case 'spoiler': return `<span class="tg-spoiler">${inner}</span>`;
        case 'code': return `<code>${inner}</code>`;
        case 'pre': return `<pre${entity.language ? ` data-language="${esc(entity.language)}"` : ''}>${inner}</pre>`;
        case 'blockquote': return `<span class="tg-quote">${inner}</span>`;
        case 'expandable_blockquote': return `<span class="tg-quote expandable" title="Expandable quote, click to toggle">${inner}</span>`;
        case 'text_link': return link(entity.url || '', inner, entity.url);
        case 'url': return link(/^[a-z][a-z0-9+.-]*:/i.test(value) ? value : `http://${value}`, inner);
        case 'email': return link(`mailto:${value}`, inner);
        case 'phone_number': return link(`tel:${value.replace(/[^\d+]/g, '')}`, inner);
        case 'text_mention': return `<span class="tg-link" title="${esc(`user ${entity.user?.id ?? ''}`)}">${inner}</span>`;
        case 'mention':
        case 'hashtag':
        case 'cashtag':
        case 'bot_command': return `<span class="tg-link">${inner}</span>`;
        case 'custom_emoji': return premiumEmoji(entity.custom_emoji_id, inner);
        case 'date_time': return `<span class="tg-time" title="${esc(dateTitle(entity.unix_time))}">${inner}</span>`;
        default: return inner;
    }
}

function dateTitle(unixTime) {
    const time = Number(unixTime);

    return Number.isFinite(time) && time > 0 ? new Date(time * 1000).toLocaleString() : 'Formatted date';
}

/**
 * A premium (custom) emoji. The real sticker isn't available here, so the
 * fallback emoji is shown with an animated premium effect.
 */
export function premiumEmoji(id, inner = '') {
    const title = `Premium emoji${id ? ` · ${id}` : ''}`;

    return `<span class="tg-premium-emoji" data-emoji-id="${esc(id ?? '')}" title="${esc(title)}">${inner || '⭐'}</span>`;
}

function normalizeEntities(entities) {
    let list = entities;

    if (typeof list === 'string') {
        try {
            list = JSON.parse(list);
        } catch (e) {
            return [];
        }
    }

    if (!Array.isArray(list)) return [];

    return list
        .filter((entity) => entity && typeof entity.type === 'string')
        .map((entity) => ({ ...entity, offset: Number(entity.offset) || 0, length: Number(entity.length) || 0 }));
}

/* ------------------------------------------------------------------
 * Telegram HTML
 * ------------------------------------------------------------------ */

const HTML_ENTITIES = {
    b: 'bold', strong: 'bold', i: 'italic', em: 'italic', u: 'underline', ins: 'underline',
    s: 'strikethrough', strike: 'strikethrough', del: 'strikethrough', 'tg-spoiler': 'spoiler', code: 'code', pre: 'pre',
};

function parseHtml(source) {
    if (typeof DOMParser === 'undefined') {
        return { text: source, entities: [] };
    }

    // DOMParser never runs scripts; only text and a few attributes are read.
    const body = new DOMParser().parseFromString(`<body>${source.replace(/\r\n/g, '\n')}</body>`, 'text/html').body;
    const state = { text: '', entities: [] };

    walkHtml(body, state, false);

    return state;
}

function walkHtml(node, state, insidePre) {
    for (const child of node.childNodes) {
        if (child.nodeType === 3) {
            state.text += child.nodeValue;
            continue;
        }

        if (child.nodeType !== 1) continue;

        const tag = child.tagName.toLowerCase();

        if (tag === 'br') {
            state.text += '\n';
            continue;
        }

        const start = state.text.length;

        walkHtml(child, state, insidePre || tag === 'pre');

        const length = state.text.length - start;
        const entity = htmlEntity(child, tag, insidePre);

        if (entity && length > 0) {
            state.entities.push({ ...entity, offset: start, length });
        }
    }
}

function htmlEntity(element, tag, insidePre) {
    if (tag === 'span' && element.classList.contains('tg-spoiler')) return { type: 'spoiler' };

    // <pre><code class="language-x"> is one pre entity with a language.
    if (tag === 'code' && insidePre) return null;

    if (tag === 'pre') {
        const code = element.querySelector(':scope > code[class*="language-"]');
        const language = code ? [...code.classList].find((name) => name.startsWith('language-')) : null;

        return language ? { type: 'pre', language: language.slice(9) } : { type: 'pre' };
    }

    if (tag === 'a') {
        const href = element.getAttribute('href') || '';
        const mention = href.match(/^tg:\/\/user\?id=(\d+)/i);

        return mention ? { type: 'text_mention', user: { id: Number(mention[1]) } } : { type: 'text_link', url: href };
    }

    if (tag === 'blockquote') return { type: element.hasAttribute('expandable') ? 'expandable_blockquote' : 'blockquote' };
    if (tag === 'tg-emoji') return { type: 'custom_emoji', custom_emoji_id: element.getAttribute('emoji-id') || '' };
    if (tag === 'tg-time') return { type: 'date_time', unix_time: Number(element.getAttribute('unix')) || 0, date_time_format: element.getAttribute('format') || '' };

    return HTML_ENTITIES[tag] ? { type: HTML_ENTITIES[tag] } : null;
}

/* ------------------------------------------------------------------
 * MarkdownV2 and legacy Markdown
 * ------------------------------------------------------------------ */

const V2_MARKERS = [['||', 'spoiler'], ['__', 'underline'], ['*', 'bold'], ['_', 'italic'], ['~', 'strikethrough']];
const V1_MARKERS = [['*', 'bold'], ['_', 'italic']];

function parseMarkdown(source, v2) {
    const state = { text: '', entities: [], open: [], links: [] };
    const markers = v2 ? V2_MARKERS : V1_MARKERS;
    let quote = null;
    let i = 0;

    const closeQuote = () => {
        if (!quote) return;

        const end = state.text.endsWith('\n') ? state.text.length - 1 : state.text.length;

        if (end > quote.start) {
            state.entities.push({ type: quote.type, offset: quote.start, length: end - quote.start });
        }

        quote = null;
    };

    while (i < source.length) {
        const atLineStart = i === 0 || source[i - 1] === '\n';

        if (v2 && atLineStart) {
            if (source.startsWith('**>', i)) {
                closeQuote();
                quote = { type: 'expandable_blockquote', start: state.text.length };
                i += 3;
                continue;
            }

            if (source[i] === '>') {
                if (!quote) quote = { type: 'blockquote', start: state.text.length };
                i += 1;
                continue;
            }

            closeQuote();
        }

        const char = source[i];

        // An escaped character is always literal; legacy Markdown only escapes its own markers.
        if (char === '\\' && i + 1 < source.length && (v2 || '_*`['.includes(source[i + 1]))) {
            state.text += source[i + 1];
            i += 2;
            continue;
        }

        // The "||" that ends an expandable quote.
        if (v2 && quote?.type === 'expandable_blockquote' && source.startsWith('||', i)
            && (i + 2 === source.length || source[i + 2] === '\n') && !state.open.some((entry) => entry.type === 'spoiler')) {
            i += 2;
            closeQuote();
            continue;
        }

        if (source.startsWith('```', i)) {
            const close = findClosing(source, '```', i + 3, v2);

            if (close !== -1) {
                let body = source.slice(i + 3, close);
                let language = '';
                const newline = body.indexOf('\n');

                if (newline !== -1 && /^[\w#+.-]*$/.test(body.slice(0, newline))) {
                    language = body.slice(0, newline);
                    body = body.slice(newline + 1);
                }

                if (body.endsWith('\n')) body = body.slice(0, -1);

                pushLiteral(state, v2 ? unescapeCode(body) : body, language ? { type: 'pre', language } : { type: 'pre' });
                i = close + 3;
                continue;
            }
        }

        if (char === '`') {
            const close = findClosing(source, '`', i + 1, v2);

            if (close !== -1) {
                const body = source.slice(i + 1, close);
                pushLiteral(state, v2 ? unescapeCode(body) : body, { type: 'code' });
                i = close + 1;
                continue;
            }
        }

        if (char === '[' || (v2 && char === '!' && source[i + 1] === '[')) {
            state.links.push({ start: state.text.length, emoji: char === '!' });
            i += char === '!' ? 2 : 1;
            continue;
        }

        if (char === ']' && state.links.length > 0 && source[i + 1] === '(') {
            const close = source.indexOf(')', i + 2);

            if (close !== -1) {
                const opened = state.links.pop();
                const url = source.slice(i + 2, close).replace(/\\([)\\])/g, '$1');
                const length = state.text.length - opened.start;

                if (length > 0) state.entities.push({ ...linkEntity(url, opened.emoji), offset: opened.start, length });

                i = close + 1;
                continue;
            }
        }

        const marker = markers.find(([token]) => source.startsWith(token, i));

        if (marker) {
            const [token, type] = marker;
            const index = state.open.map((entry) => entry.type).lastIndexOf(type);

            if (index !== -1) {
                const [opened] = state.open.splice(index, 1);
                const length = state.text.length - opened.start;

                if (length > 0) state.entities.push({ type, offset: opened.start, length });
            } else {
                state.open.push({ type, start: state.text.length });
            }

            i += token.length;
            continue;
        }

        state.text += char;
        i += 1;
    }

    closeQuote();

    return { text: state.text, entities: state.entities };
}

function findClosing(source, token, from, v2) {
    for (let i = from; i < source.length; i++) {
        if (source[i] === '\\' && v2) {
            i += 1;
            continue;
        }

        if (source.startsWith(token, i)) return i;
    }

    return -1;
}

function unescapeCode(body) {
    return body.replace(/\\([`\\])/g, '$1');
}

function pushLiteral(state, value, entity) {
    const start = state.text.length;

    state.text += value;

    if (value.length > 0) state.entities.push({ ...entity, offset: start, length: value.length });
}

function linkEntity(url, isEmoji) {
    const emoji = url.match(/^tg:\/\/emoji\?id=(\d+)/i);

    if (isEmoji && emoji) return { type: 'custom_emoji', custom_emoji_id: emoji[1] };

    const time = url.match(/^tg:\/\/time\?unix=(\d+)/i);

    if (isEmoji && time) return { type: 'date_time', unix_time: Number(time[1]) };

    const mention = url.match(/^tg:\/\/user\?id=(\d+)/i);

    return mention ? { type: 'text_mention', user: { id: Number(mention[1]) } } : { type: 'text_link', url };
}
