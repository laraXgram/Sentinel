<?php

namespace LaraGram\Sentinel\Telegram;

use LaraGram\Support\Str;

class DryRunInterceptor
{
    /**
     * The message IDs handed out to fake messages.
     *
     * @var int
     */
    protected int $messageId;

    /**
     * Create a new interceptor.
     */
    public function __construct()
    {
        $this->messageId = random_int(100000, 900000);
    }

    /**
     * Answer a Telegram API call locally, the way Telegram would.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @param  string|null  $connection
     * @return array
     */
    public function __invoke(string $method, array $parameters, ?string $connection = null): array
    {
        return ['ok' => true, 'result' => $this->result($method, $parameters, $connection)];
    }

    /**
     * Build a plausible result for a method.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @param  string|null  $connection
     * @return mixed
     */
    protected function result(string $method, array $parameters, ?string $connection): mixed
    {
        $lower = strtolower($method);

        return match (true) {
            $lower === 'getme' => $this->bot($connection),
            $lower === 'getchat' => $this->chat($parameters['chat_id'] ?? 0),
            $lower === 'getchatmember' => ['status' => 'member', 'user' => ['id' => (int) ($parameters['user_id'] ?? 0), 'is_bot' => false, 'first_name' => 'User']],
            $lower === 'getchatmembercount' => 1,
            $lower === 'getwebhookinfo' => ['url' => '', 'has_custom_certificate' => false, 'pending_update_count' => 0],
            $lower === 'getmycommands' => [],
            $lower === 'getfile' => ['file_id' => $parameters['file_id'] ?? '', 'file_unique_id' => Str::random(12), 'file_size' => 0, 'file_path' => 'documents/file.bin'],
            $lower === 'getupdates' => [],
            $lower === 'sendmediagroup' => array_map(fn ($media) => $this->message($parameters, ['caption' => $media['caption'] ?? null]), $this->decode($parameters['media'] ?? [])),
            $lower === 'forwardmessages', $lower === 'copymessages' => array_map(fn () => ['message_id' => ++$this->messageId], $this->decode($parameters['message_ids'] ?? [])),
            $lower === 'copymessage' => ['message_id' => ++$this->messageId],
            $lower === 'stoppoll' => ['id' => Str::random(16), 'question' => '', 'options' => [], 'is_closed' => true],
            Str::startsWith($lower, 'send') || $lower === 'forwardmessage' => $this->message($parameters),
            Str::startsWith($lower, 'edit') => isset($parameters['inline_message_id']) ? true : $this->message($parameters, ['message_id' => (int) ($parameters['message_id'] ?? ++$this->messageId), 'edit_date' => time()]),
            $lower === 'createchatinvitelink', $lower === 'editchatinvitelink' => ['invite_link' => 'https://t.me/+'.Str::random(16), 'creator' => $this->bot($connection), 'creates_join_request' => false, 'is_primary' => false, 'is_revoked' => false],
            $lower === 'exportchatinvitelink' => 'https://t.me/+'.Str::random(16),
            default => true,
        };
    }

    /**
     * Build a fake message sent by the bot.
     *
     * @param  array  $parameters
     * @param  array  $overrides
     * @return array
     */
    protected function message(array $parameters, array $overrides = []): array
    {
        return array_filter(array_merge([
            'message_id' => ++$this->messageId,
            'from' => $this->bot(null),
            'chat' => $this->chat($parameters['chat_id'] ?? 0),
            'date' => time(),
            'text' => $parameters['text'] ?? null,
            'caption' => $parameters['caption'] ?? null,
            'reply_markup' => isset($parameters['reply_markup']) ? $this->decode($parameters['reply_markup']) : null,
        ], $overrides), fn ($value) => $value !== null);
    }

    /**
     * Build a fake chat.
     *
     * @param  int|string  $id
     * @return array
     */
    protected function chat(int|string $id): array
    {
        $numeric = is_numeric($id) ? (int) $id : 0;

        return $numeric < 0
            ? ['id' => $numeric, 'type' => str_starts_with((string) $id, '-100') ? 'supergroup' : 'group', 'title' => 'Group']
            : ['id' => is_numeric($id) ? $numeric : $id, 'type' => is_numeric($id) ? 'private' : 'channel', 'first_name' => 'User'];
    }

    /**
     * Build the fake bot account.
     *
     * @param  string|null  $connection
     * @return array
     */
    protected function bot(?string $connection): array
    {
        $connection ??= config('bot.default');
        $config = (array) config("bot.connections.{$connection}", []);
        $id = (int) (($config['userid'] ?? '') ?: explode(':', (string) ($config['token'] ?? '0'))[0]);

        return [
            'id' => $id,
            'is_bot' => true,
            'first_name' => config('app.name', 'Bot'),
            'username' => ($config['username'] ?? '') ?: 'bot',
        ];
    }

    /**
     * Decode a parameter that may have been JSON encoded.
     *
     * @param  mixed  $value
     * @return array
     */
    protected function decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
