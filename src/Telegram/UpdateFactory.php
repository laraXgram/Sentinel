<?php

namespace LaraGram\Sentinel\Telegram;

use InvalidArgumentException;

class UpdateFactory
{
    /**
     * Build a fake update from the playground form.
     *
     * @param  array  $input
     * @return array
     */
    public function make(array $input): array
    {
        $kind = $input['kind'] ?? 'text';

        if ($kind === 'raw') {
            $update = is_array($input['raw'] ?? null) ? $input['raw'] : json_decode((string) ($input['raw'] ?? ''), true);

            if (! is_array($update) || $update === []) {
                throw new InvalidArgumentException('The raw update is not valid JSON.');
            }

            $update['update_id'] ??= $this->updateId();

            return $update;
        }

        $user = $this->user($input);
        $chat = $this->chat($input, $user);
        $text = (string) ($input['text'] ?? '');

        return ['update_id' => $this->updateId()] + match ($kind) {
            'command' => ['message' => $this->message($chat, $user, [
                'text' => $command = '/'.ltrim($text !== '' ? $text : 'start', '/'),
                'entities' => [['type' => 'bot_command', 'offset' => 0, 'length' => mb_strlen(explode(' ', $command)[0])]],
            ])],
            'callback' => ['callback_query' => [
                'id' => (string) random_int(10 ** 15, 10 ** 16 - 1),
                'from' => $user,
                'message' => $this->message($chat, $this->bot(), ['text' => (string) ($input['message_text'] ?? 'Message with buttons')]),
                'chat_instance' => (string) random_int(10 ** 15, 10 ** 16 - 1),
                'data' => $text,
            ]],
            'inline_query' => ['inline_query' => [
                'id' => (string) random_int(10 ** 15, 10 ** 16 - 1),
                'from' => $user,
                'query' => $text,
                'offset' => '',
                'chat_type' => 'sender',
            ]],
            'contact' => ['message' => $this->message($chat, $user, [
                'contact' => ['phone_number' => $text !== '' ? $text : '+10000000000', 'first_name' => $user['first_name'], 'user_id' => $user['id']],
            ])],
            'location' => ['message' => $this->message($chat, $user, [
                'location' => array_combine(['latitude', 'longitude'], array_map('floatval', array_pad(explode(',', $text !== '' ? $text : '35.6892,51.3890'), 2, 0))),
            ])],
            'photo' => ['message' => $this->message($chat, $user, array_filter([
                'photo' => [['file_id' => 'AgACAgQAAxkBAAI'.bin2hex(random_bytes(8)), 'file_unique_id' => bin2hex(random_bytes(6)), 'width' => 1280, 'height' => 720, 'file_size' => 102400]],
                'caption' => $text !== '' ? $text : null,
            ]))],
            'my_chat_member' => ['my_chat_member' => [
                'chat' => $chat,
                'from' => $user,
                'date' => time(),
                'old_chat_member' => ['status' => $text === 'kicked' ? 'member' : 'kicked', 'user' => $this->bot()],
                'new_chat_member' => ['status' => $text === 'kicked' ? 'kicked' : 'member', 'user' => $this->bot()],
            ]],
            'text' => ['message' => $this->message($chat, $user, ['text' => $text !== '' ? $text : 'Hello'])],
            default => throw new InvalidArgumentException("Unknown update kind [{$kind}]."),
        };
    }

    /**
     * Build the user sending the update.
     *
     * @param  array  $input
     * @return array
     */
    protected function user(array $input): array
    {
        return array_filter([
            'id' => (int) ($input['user_id'] ?? 0) ?: 100000001,
            'is_bot' => false,
            'first_name' => ($input['first_name'] ?? '') ?: 'Sentinel',
            'username' => ($input['username'] ?? '') ?: null,
            'language_code' => ($input['language_code'] ?? '') ?: 'en',
        ], fn ($value) => $value !== null);
    }

    /**
     * Build the chat of the update.
     *
     * @param  array  $input
     * @param  array  $user
     * @return array
     */
    protected function chat(array $input, array $user): array
    {
        $type = $input['chat_type'] ?? 'private';

        if ($type === 'private') {
            return array_filter([
                'id' => $user['id'],
                'type' => 'private',
                'first_name' => $user['first_name'],
                'username' => $user['username'] ?? null,
            ], fn ($value) => $value !== null);
        }

        return [
            'id' => (int) ($input['chat_id'] ?? 0) ?: -1001000000001,
            'type' => $type,
            'title' => ($input['chat_title'] ?? '') ?: 'Sentinel Group',
        ];
    }

    /**
     * Build a message.
     *
     * @param  array  $chat
     * @param  array  $from
     * @param  array  $content
     * @return array
     */
    protected function message(array $chat, array $from, array $content): array
    {
        return [
            'message_id' => random_int(1000, 999999),
            'from' => $from,
            'chat' => $chat,
            'date' => time(),
        ] + $content;
    }

    /**
     * Build the bot account.
     *
     * @return array
     */
    protected function bot(): array
    {
        $config = (array) config('bot.connections.'.config('bot.default'), []);

        return [
            'id' => (int) (($config['userid'] ?? '') ?: explode(':', (string) ($config['token'] ?? '1'))[0]),
            'is_bot' => true,
            'first_name' => config('app.name', 'Bot'),
            'username' => ($config['username'] ?? '') ?: 'bot',
        ];
    }

    /**
     * Get a random update ID.
     *
     * @return int
     */
    protected function updateId(): int
    {
        return random_int(100000000, 999999999);
    }
}
