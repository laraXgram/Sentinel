import { html, raw, esc } from '../core/dom.js';
import { icon } from '../core/icons.js';
import * as fmt from '../core/format.js';
import { avatar } from './ui.js';
import * as richText from './richText.js';

export const UPDATE_TYPES = {
    message: { label: 'Message', icon: 'message' },
    edited_message: { label: 'Edited message', icon: 'edit' },
    channel_post: { label: 'Channel post', icon: 'radio' },
    edited_channel_post: { label: 'Edited post', icon: 'edit' },
    business_message: { label: 'Business message', icon: 'message' },
    callback_query: { label: 'Callback query', icon: 'pointer' },
    inline_query: { label: 'Inline query', icon: 'at' },
    chosen_inline_result: { label: 'Inline result', icon: 'check' },
    my_chat_member: { label: 'Bot membership', icon: 'door' },
    chat_member: { label: 'Chat member', icon: 'users' },
    chat_join_request: { label: 'Join request', icon: 'userPlus' },
    message_reaction: { label: 'Reaction', icon: 'heart' },
    message_reaction_count: { label: 'Reaction count', icon: 'heart' },
    poll: { label: 'Poll', icon: 'poll' },
    poll_answer: { label: 'Poll answer', icon: 'poll' },
    pre_checkout_query: { label: 'Pre-checkout', icon: 'card' },
    shipping_query: { label: 'Shipping query', icon: 'card' },
    chat_boost: { label: 'Chat boost', icon: 'sparkles' },
};

export const KIND_ICONS = {
    text: 'message', command: 'command', callback: 'pointer', photo: 'image', video: 'video', animation: 'video',
    audio: 'mic', voice: 'mic', video_note: 'video', document: 'file', sticker: 'smile', location: 'mapPin',
    venue: 'mapPin', contact: 'phone', poll: 'poll', dice: 'dice', successful_payment: 'card', invoice: 'card',
    new_chat_members: 'userPlus', left_chat_member: 'door', kicked: 'ban', member: 'userPlus', administrator: 'shield',
};

export function updateMeta(type) {
    return UPDATE_TYPES[type] || { label: fmt.title(type || 'unknown'), icon: 'inbox' };
}

export function personName(person) {
    if (!person) return 'Unknown';

    const name = person.title || [person.first_name, person.last_name].filter(Boolean).join(' ');

    return name || (person.username ? `@${person.username}` : String(person.id ?? 'Unknown'));
}

export function personSub(person) {
    if (!person) return '';

    return [person.username ? `@${person.username}` : null, person.id].filter(Boolean).join(' · ');
}

export const STATUS = {
    handled: { tone: 'success', label: 'Handled', icon: 'check' },
    unhandled: { tone: 'warning', label: 'Unhandled', icon: 'alertTriangle' },
    failed: { tone: 'error', label: 'Failed', icon: 'alertCircle' },
};

export function statusBadge(status) {
    const meta = STATUS[status] || { tone: '', label: fmt.title(status || 'unknown') };

    return html`<span class="badge ${meta.tone}">${meta.icon ? icon(meta.icon) : ''}${meta.label}</span>`;
}

/* ------------------------------------------------------------------
 * Telegram message formatting
 * ------------------------------------------------------------------ */

const TEXT_MODE_KEY = 'sentinel.chatTextMode';

/**
 * How message text is shown in chat previews: "formatted" (default) or "raw".
 */
export function textMode() {
    try {
        return localStorage.getItem(TEXT_MODE_KEY) === 'raw' ? 'raw' : 'formatted';
    } catch (e) {
        return 'formatted';
    }
}

/**
 * Switch every chat preview on the page to the given text mode and remember it.
 */
export function setTextMode(mode) {
    const value = mode === 'raw' ? 'raw' : 'formatted';

    try {
        localStorage.setItem(TEXT_MODE_KEY, value);
    } catch (e) {
        //
    }

    document.querySelectorAll('.chat-preview').forEach((preview) => {
        preview.dataset.textMode = value;
        preview.querySelectorAll('[data-chat-text-mode]').forEach((button) => {
            button.classList.toggle('active', button.dataset.chatTextMode === value);
        });
    });
}

/**
 * Render Telegram text both formatted (HTML, MarkdownV2, Markdown or
 * entities, as the Telegram apps show it) and raw (exactly as sent); the
 * preview's text mode decides which one is visible.
 */
export function formatText(text, parseMode = null, entities = null) {
    const source = String(text ?? '');
    const parsed = richText.parse(source, parseMode, entities);
    const mode = String(parseMode || '').trim();

    return raw(
        `<span class="bubble-text tg-formatted">${richText.render(parsed.text, parsed.entities)}</span>`
        + `<span class="bubble-text tg-raw">${mode ? `<span class="tg-raw-mode">${esc(mode)}</span>` : ''}${esc(source)}</span>`,
    );
}

function decode(value) {
    if (typeof value === 'string') {
        try {
            return JSON.parse(value);
        } catch (e) {
            return null;
        }
    }

    return value || null;
}

export function keyboard(markup) {
    const data = decode(markup);

    if (!data) return '';

    if (Array.isArray(data.inline_keyboard)) {
        return html`
            <div class="inline-keyboard">
                ${data.inline_keyboard.map((row) => html`
                    <div class="inline-row">
                        ${row.map((button) => html`<span class="${buttonClass(button)}" title="${button.callback_data ? `callback_data: ${button.callback_data}` : button.url || ''}">${buttonIcon(button)}${button.text}${button.url ? icon('external') : button.web_app ? icon('globe') : button.switch_inline_query !== undefined ? icon('at') : ''}</span>`)}
                    </div>`)}
            </div>`;
    }

    if (Array.isArray(data.keyboard)) {
        return html`
            <div class="reply-keyboard">
                ${data.keyboard.map((row) => html`<div class="inline-row">${row.map((button) => typeof button === 'string' ? html`<span class="inline-btn">${button}</span>` : html`<span class="${buttonClass(button)}">${buttonIcon(button)}${button.text}</span>`)}</div>`)}
            </div>`;
    }

    if (data.remove_keyboard) {
        return html`<div class="bubble-note">keyboard removed</div>`;
    }

    return '';
}

const BUTTON_STYLES = ['danger', 'success', 'primary'];

function buttonClass(button) {
    const style = BUTTON_STYLES.includes(button?.style) ? ` btn-${button.style}` : '';

    return `inline-btn${style}${button?.icon_custom_emoji_id ? ' has-premium-icon' : ''}`;
}

/**
 * The custom emoji a button shows before its text, with the premium effect.
 */
function buttonIcon(button) {
    return button?.icon_custom_emoji_id ? raw(richText.premiumEmoji(button.icon_custom_emoji_id, '✨')) : '';
}

const MEDIA = {
    photo: ['image', 'Photo'], video: ['video', 'Video'], animation: ['video', 'GIF'], audio: ['mic', 'Audio'],
    voice: ['mic', 'Voice message'], video_note: ['video', 'Video message'], document: ['file', 'Document'],
    sticker: ['smile', 'Sticker'], location: ['mapPin', 'Location'], venue: ['mapPin', 'Venue'],
    contact: ['phone', 'Contact'], poll: ['poll', 'Poll'], dice: ['dice', 'Dice'], invoice: ['card', 'Invoice'],
};

function mediaBox(kind, detail = '') {
    const [iconName, label] = MEDIA[kind] || ['file', fmt.title(kind)];

    return html`<div class="bubble-media">${icon(iconName)}<div><div style="font-weight:600">${label}</div>${detail ? html`<div class="bubble-note">${detail}</div>` : ''}</div></div>`;
}

function time(timestamp) {
    return new Date((timestamp || Date.now() / 1000) * 1000).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

/**
 * Render the incoming update as the user's side of the chat.
 */
function incoming(update, summary) {
    const type = summary?.type;
    const payload = update?.[type] || {};

    if (['message', 'edited_message', 'channel_post', 'business_message', 'edited_channel_post', 'edited_business_message'].includes(type)) {
        const kind = Object.keys(MEDIA).find((key) => payload[key] !== undefined);
        const text = payload.text ?? payload.caption ?? '';
        const entities = payload.text !== undefined ? payload.entities : payload.caption_entities;

        if (!kind && !text) {
            const service = payload.new_chat_members ? `${payload.new_chat_members.map(personName).join(', ')} joined`
                : payload.left_chat_member ? `${personName(payload.left_chat_member)} left`
                : summary.kind ? fmt.title(summary.kind) : 'Service message';

            return html`<div class="service-msg">${service}</div>`;
        }

        let detail = '';

        if (kind === 'location') detail = `${payload.location.latitude}, ${payload.location.longitude}`;
        if (kind === 'contact') detail = `${payload.contact.first_name || ''} ${payload.contact.phone_number || ''}`;
        if (kind === 'document') detail = payload.document.file_name || '';
        if (kind === 'sticker') detail = payload.sticker.emoji || '';
        if (kind === 'dice') detail = `${payload.dice.emoji} ${payload.dice.value}`;
        if (kind === 'poll') detail = payload.poll.question;

        return html`
            <div class="msg out">
                <div class="bubble">${kind ? mediaBox(kind, detail) : ''}${text ? formatText(text, null, entities) : ''}<span class="meta">${type.startsWith('edited') ? 'edited ' : ''}${time(payload.date)} ✓✓</span></div>
            </div>`;
    }

    if (type === 'callback_query') {
        return html`
            ${payload.message?.text ? html`<div class="msg in"><div class="bubble">${formatText(payload.message.text, null, payload.message.entities)}<span class="meta">${time(payload.message.date)}</span></div>${keyboard(payload.message.reply_markup)}</div>` : ''}
            <div class="service-msg">👆 ${personName(payload.from)} tapped <b>${payload.data ?? payload.game_short_name ?? 'a button'}</b></div>`;
    }

    if (type === 'inline_query') {
        return html`<div class="msg out"><div class="bubble"><span class="bubble-note">@bot</span> ${payload.query || '(empty inline query)'}<span class="meta">inline</span></div></div>`;
    }

    if (type === 'my_chat_member') {
        const status = payload.new_chat_member?.status;
        const text = status === 'kicked' ? `${personName(payload.from)} blocked the bot` : status === 'member' ? `${personName(payload.from)} started the bot` : `Bot is now ${status}`;

        return html`<div class="service-msg">${text}</div>`;
    }

    return html`<div class="service-msg">${updateMeta(type).label}${summary?.text ? `: ${summary.text}` : ''}</div>`;
}

/**
 * Render one Bot API call as the bot's side of the chat.
 */
function outgoing(entry) {
    const content = entry.content || {};
    const method = content.method || '';
    const p = content.parameters || {};
    const failed = content.ok === false;
    const lower = method.toLowerCase();

    const error = failed ? html`<div class="msg-error">${content.error_code ?? ''} ${content.description || 'Failed'}</div>` : '';
    const meta = html`<span class="meta">${content.intercepted ? 'dry run · ' : ''}${fmt.duration(content.duration)}</span>`;
    const label = html`<div class="msg-method">${method}</div>`;

    if (lower === 'answercallbackquery') {
        return p.text ? html`<div class="toast-msg">${p.show_alert ? '⚠️ ' : ''}${p.text}</div>` : html`<div class="service-msg">callback answered</div>`;
    }

    if (lower === 'sendchataction') {
        return html`<div class="service-msg">bot is ${String(p.action || 'typing').replace(/_/g, ' ')}…</div>`;
    }

    if (lower === 'deletemessage' || lower === 'deletemessages') {
        return html`<div class="service-msg">🗑 message deleted${failed ? ' (failed)' : ''}</div>`;
    }

    if (lower === 'answerinlinequery') {
        const results = decode(p.results) || [];

        return html`<div class="service-msg">answered inline query with ${results.length} result${results.length === 1 ? '' : 's'}</div>`;
    }

    const isSend = lower.startsWith('send') || lower.startsWith('edit') || lower === 'copymessage' || lower === 'forwardmessage';

    if (!isSend) {
        return html`<div class="service-msg"><span class="mono">${method}</span>${failed ? ' failed' : ''}</div>`;
    }

    const kind = lower.replace(/^send/, '').replace(/^edit(message)?/, '');
    const mediaKind = { photo: 'photo', video: 'video', animation: 'animation', audio: 'audio', voice: 'voice', videonote: 'video_note', document: 'document', sticker: 'sticker', location: 'location', venue: 'venue', contact: 'contact', poll: 'poll', dice: 'dice', invoice: 'invoice' }[kind];

    let detail = '';

    if (mediaKind === 'poll') detail = p.question;
    if (mediaKind === 'location') detail = `${p.latitude}, ${p.longitude}`;
    if (mediaKind === 'contact') detail = `${p.first_name || ''} ${p.phone_number || ''}`;
    if (mediaKind === 'invoice') detail = p.title;
    if (mediaKind && typeof p[mediaKind] === 'string' && /^https?:/.test(p[mediaKind])) detail = p[mediaKind];
    if (mediaKind === 'photo' && typeof p.photo === 'string' && /^https?:/.test(p.photo)) detail = '';

    const photo = mediaKind === 'photo' && typeof p.photo === 'string' && /^https?:/.test(p.photo)
        ? html`<div class="bubble-media" style="padding:0;background:none"><img src="${p.photo}" alt="" loading="lazy" onerror="this.parentNode.innerHTML='🖼 Photo'"></div>`
        : null;

    const text = p.text ?? p.caption ?? '';
    const edited = lower.startsWith('edit');

    return html`
        <div class="msg in ${failed ? 'msg-failed' : ''}">
            ${label}
            <div class="bubble">
                ${photo || (mediaKind ? mediaBox(mediaKind, detail) : '')}
                ${text ? formatText(text, p.parse_mode, p.text !== undefined ? p.entities : p.caption_entities) : ''}
                ${!text && !mediaKind && edited ? html`<span class="bubble-note">reply markup updated</span>` : ''}
                <span class="meta">${edited ? 'edited · ' : ''}${meta}</span>
            </div>
            ${keyboard(p.reply_markup)}
            ${error}
        </div>`;
}

/**
 * A Telegram-like replay of an update and everything the bot sent back.
 */
export function chatPreview({ update, summary, calls = [], bot = null }) {
    const chat = summary?.chat || summary?.user;
    const title = chat ? personName(chat) : 'Chat';

    const mode = textMode();

    return html`
        <div class="chat-preview" data-text-mode="${mode}">
        <div class="chat-head">
            ${avatar(title, chat?.id)}
            <div class="identity-text grow">
                <span class="identity-name">${title}</span>
                <span class="identity-sub">${chat?.type ? fmt.title(chat.type) : ''}${bot ? ` · with @${bot}` : ''}</span>
            </div>
            <div class="segmented sm" role="group" aria-label="Message text">
                <button type="button" class="${mode === 'formatted' ? 'active' : ''}" data-chat-text-mode="formatted" title="Show the text the way Telegram formats it">Formatted</button>
                <button type="button" class="${mode === 'raw' ? 'active' : ''}" data-chat-text-mode="raw" title="Show the text exactly as it was sent">Raw</button>
            </div>
            <span class="badge brand">${icon('eye')} Preview</span>
        </div>
        <div class="chat">
            <div class="chat-day">${new Date((summary?.date || Date.now() / 1000) * 1000).toLocaleDateString(undefined, { month: 'long', day: 'numeric' })}</div>
            ${update ? incoming(update, summary) : ''}
            ${calls.map(outgoing)}
            ${calls.length === 0 ? html`<div class="service-msg">the bot did not call the Telegram API</div>` : ''}
        </div>
        </div>`;
}
