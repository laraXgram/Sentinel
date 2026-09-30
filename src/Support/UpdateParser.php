<?php

namespace LaraGram\Sentinel\Support;

class UpdateParser
{
    /**
     * Every update type the Bot API can deliver.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        'message', 'edited_message', 'channel_post', 'edited_channel_post',
        'business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages',
        'message_reaction', 'message_reaction_count', 'inline_query', 'chosen_inline_result',
        'callback_query', 'shipping_query', 'pre_checkout_query', 'purchased_paid_media',
        'poll', 'poll_answer', 'my_chat_member', 'chat_member', 'chat_join_request',
        'chat_boost', 'removed_chat_boost',
    ];

    /**
     * The message contents, in the order they are detected.
     *
     * @var array<int, string>
     */
    protected const MESSAGE_KINDS = [
        'text', 'photo', 'video', 'animation', 'audio', 'voice', 'video_note', 'document',
        'sticker', 'paid_media', 'story', 'location', 'venue', 'contact', 'poll', 'dice', 'game',
        'invoice', 'successful_payment', 'refunded_payment', 'users_shared', 'chat_shared',
        'web_app_data', 'new_chat_members', 'left_chat_member', 'new_chat_title', 'new_chat_photo',
        'delete_chat_photo', 'pinned_message', 'migrate_to_chat_id', 'migrate_from_chat_id',
        'forum_topic_created', 'forum_topic_closed', 'forum_topic_reopened', 'video_chat_started',
        'video_chat_ended', 'boost_added', 'giveaway', 'giveaway_winners', 'checklist',
    ];

    /**
     * Summarize an update into the fields the dashboard shows.
     *
     * @param  array  $update
     * @return array{update_id: int|null, type: string, kind: string, command: string|null, chat: array|null, user: array|null, text: string|null, date: int|null, message_id: int|null}
     */
    public static function summarize(array $update): array
    {
        $type = static::type($update);
        $payload = $type !== 'unknown' ? ($update[$type] ?? []) : [];
        $message = static::message($type, $payload);

        $text = $message['text'] ?? $message['caption'] ?? null;
        $command = static::command($message);

        return [
            'update_id' => $update['update_id'] ?? null,
            'type' => $type,
            'kind' => static::kind($type, $payload, $message, $command),
            'command' => $command,
            'chat' => static::chat($type, $payload, $message),
            'user' => static::user($type, $payload),
            'text' => static::preview($type, $payload, $text),
            'date' => $payload['date'] ?? $message['date'] ?? null,
            'message_id' => $message['message_id'] ?? null,
        ];
    }

    /**
     * Get the type of the update.
     *
     * @param  array  $update
     * @return string
     */
    public static function type(array $update): string
    {
        foreach (static::TYPES as $type) {
            if (isset($update[$type])) {
                return $type;
            }
        }

        foreach (array_keys($update) as $key) {
            if ($key !== 'update_id') {
                return (string) $key;
            }
        }

        return 'unknown';
    }

    /**
     * Get the message an update is about, if any.
     *
     * @param  string  $type
     * @param  array  $payload
     * @return array
     */
    protected static function message(string $type, array $payload): array
    {
        return match ($type) {
            'message', 'edited_message', 'channel_post', 'edited_channel_post',
            'business_message', 'edited_business_message' => $payload,
            'callback_query' => $payload['message'] ?? [],
            default => [],
        };
    }

    /**
     * Get the bot command of a message, if it starts with one.
     *
     * @param  array  $message
     * @return string|null
     */
    protected static function command(array $message): ?string
    {
        $entity = $message['entities'][0] ?? null;

        if (! isset($message['text']) || ($entity['type'] ?? null) !== 'bot_command' || ($entity['offset'] ?? 1) !== 0) {
            return null;
        }

        $command = mb_substr($message['text'], 0, $entity['length'] ?? mb_strlen($message['text']));

        return explode('@', $command)[0];
    }

    /**
     * Get the content kind of the update.
     *
     * @param  string  $type
     * @param  array  $payload
     * @param  array  $message
     * @param  string|null  $command
     * @return string
     */
    protected static function kind(string $type, array $payload, array $message, ?string $command): string
    {
        if ($type === 'callback_query') {
            return isset($payload['game_short_name']) ? 'game' : 'callback';
        }

        if ($type === 'my_chat_member' || $type === 'chat_member') {
            return ($payload['new_chat_member']['status'] ?? 'member');
        }

        if ($message === []) {
            return $type;
        }

        if ($command !== null) {
            return 'command';
        }

        foreach (static::MESSAGE_KINDS as $kind) {
            if (isset($message[$kind])) {
                return $kind;
            }
        }

        return 'service';
    }

    /**
     * Get the chat an update belongs to.
     *
     * @param  string  $type
     * @param  array  $payload
     * @param  array  $message
     * @return array|null
     */
    protected static function chat(string $type, array $payload, array $message): ?array
    {
        $chat = $message['chat'] ?? $payload['chat'] ?? null;

        if ($chat === null && in_array($type, ['inline_query', 'chosen_inline_result', 'shipping_query', 'pre_checkout_query', 'poll_answer'], true)) {
            $from = $payload['from'] ?? $payload['user'] ?? null;

            $chat = $from ? ['id' => $from['id'], 'type' => 'private', 'first_name' => $from['first_name'] ?? null, 'username' => $from['username'] ?? null] : null;
        }

        if (! is_array($chat) || ! isset($chat['id'])) {
            return null;
        }

        return array_filter([
            'id' => $chat['id'],
            'type' => $chat['type'] ?? null,
            'title' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: null,
            'username' => $chat['username'] ?? null,
            'is_forum' => $chat['is_forum'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Get the user who caused the update.
     *
     * @param  string  $type
     * @param  array  $payload
     * @return array|null
     */
    protected static function user(string $type, array $payload): ?array
    {
        $user = $payload['from']
            ?? $payload['user']
            ?? $payload['boost']['source']['user']
            ?? $payload['source']['user']
            ?? null;

        if (! is_array($user) || ! isset($user['id'])) {
            return null;
        }

        return array_filter([
            'id' => $user['id'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'username' => $user['username'] ?? null,
            'language_code' => $user['language_code'] ?? null,
            'is_bot' => $user['is_bot'] ?? null,
            'is_premium' => $user['is_premium'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Get a short human readable preview of the update.
     *
     * @param  string  $type
     * @param  array  $payload
     * @param  string|null  $text
     * @return string|null
     */
    protected static function preview(string $type, array $payload, ?string $text): ?string
    {
        $preview = match ($type) {
            'callback_query' => $payload['data'] ?? $payload['game_short_name'] ?? null,
            'inline_query' => $payload['query'] ?? null,
            'chosen_inline_result' => $payload['result_id'] ?? $payload['query'] ?? null,
            'poll' => $payload['question'] ?? null,
            'poll_answer' => isset($payload['option_ids']) ? 'options: '.implode(', ', $payload['option_ids']) : null,
            'my_chat_member', 'chat_member' => ($payload['old_chat_member']['status'] ?? '?').' → '.($payload['new_chat_member']['status'] ?? '?'),
            'chat_join_request' => $payload['bio'] ?? 'join request',
            'message_reaction' => implode(' ', array_map(fn ($reaction) => $reaction['emoji'] ?? $reaction['type'] ?? '', $payload['new_reaction'] ?? [])),
            'pre_checkout_query', 'shipping_query' => $payload['invoice_payload'] ?? null,
            default => $text,
        };

        if ($preview === null) {
            return null;
        }

        $preview = preg_replace('/\s+/u', ' ', (string) $preview);

        return mb_strlen($preview) > 160 ? mb_substr($preview, 0, 157).'...' : $preview;
    }

    /**
     * Get a display name for a user or chat summary.
     *
     * @param  array|null  $subject
     * @return string|null
     */
    public static function name(?array $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $name = $subject['title'] ?? trim(($subject['first_name'] ?? '').' '.($subject['last_name'] ?? ''));

        return $name !== '' ? $name : ($subject['username'] ?? (string) ($subject['id'] ?? ''));
    }
}
