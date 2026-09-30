<?php

namespace LaraGram\Sentinel\Telegram;

use LaraGram\Request\Request as BotRequest;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Support\Str;
use Throwable;

class BotInspector
{
    /**
     * Get the configured bot connections.
     *
     * @return array<string, array>
     */
    public function connections(): array
    {
        $connections = [];

        foreach ((array) config('bot.connections', []) as $name => $connection) {
            if (! is_array($connection)) {
                continue;
            }

            $token = $connection['token'] ?? null;

            $connections[$name] = [
                'name' => $name,
                'token' => Redactor::token($token),
                'configured' => ! empty($token),
                'bot_id' => $token ? (int) explode(':', $token)[0] : null,
                'url' => $connection['url'] ?? null,
                'username' => ($connection['username'] ?? '') ?: null,
                'secret_token' => ! empty($connection['secret_token']),
                'allowed_updates' => $connection['allowed_updates'] ?? null,
                'default' => config('bot.default') === $name,
            ];
        }

        return $connections;
    }

    /**
     * Determine if a connection is configured.
     *
     * @param  string  $connection
     * @return bool
     */
    public function has(string $connection): bool
    {
        return isset($this->connections()[$connection]);
    }

    /**
     * Call a Bot API method on a connection without recording it.
     *
     * @param  string  $connection
     * @param  string  $method
     * @param  array  $parameters
     * @return array{ok: bool, result?: mixed, error_code?: int, description?: string, duration: float}
     */
    public function call(string $connection, string $method, array $parameters = []): array
    {
        return Sentinel::withoutRecording(function () use ($connection, $method, $parameters) {
            $started = hrtime(true);

            try {
                $response = (new BotRequest)->connection($connection)->silent()->call($method, $parameters);

                $payload = $response instanceof \LaraGram\Laraquest\Response
                    ? $response->toArray(full: true)
                    : (is_array($response) ? $response : json_decode(json_encode($response), true));
            } catch (Throwable $e) {
                $payload = [
                    'ok' => false,
                    'error_code' => $e->getCode() ?: 500,
                    'description' => Redactor::string($e->getMessage()),
                ];
            }

            return (array) $payload + ['ok' => false, 'duration' => round((hrtime(true) - $started) / 1e6, 1)];
        });
    }

    /**
     * Get the bot account behind a connection.
     *
     * @param  string  $connection
     * @return array
     */
    public function me(string $connection): array
    {
        return $this->call($connection, 'getMe');
    }

    /**
     * Get the webhook status of a connection, with a health report.
     *
     * @param  string  $connection
     * @return array
     */
    public function webhook(string $connection): array
    {
        $response = $this->call($connection, 'getWebhookInfo');

        if (! ($response['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $response['description'] ?? 'Telegram could not be reached.',
                'error_code' => $response['error_code'] ?? null,
                'latency' => $response['duration'] ?? null,
                'health' => [
                    'status' => 'error',
                    'score' => 0,
                    'issues' => [[
                        'level' => 'error',
                        'title' => 'Telegram API unreachable',
                        'detail' => $response['description'] ?? 'The getWebhookInfo call failed.',
                    ]],
                ],
            ];
        }

        $info = (array) ($response['result'] ?? []);

        return [
            'ok' => true,
            'info' => $info,
            'latency' => $response['duration'] ?? null,
            'health' => $this->health($connection, $info),
            'checked_at' => time(),
        ];
    }

    /**
     * Analyse the webhook info of a connection.
     *
     * @param  string  $connection
     * @param  array  $info
     * @return array{status: string, score: int, issues: array<int, array{level: string, title: string, detail: string}>}
     */
    public function health(string $connection, array $info): array
    {
        $issues = [];
        $config = $this->connections()[$connection] ?? [];
        $url = (string) ($info['url'] ?? '');
        $pending = (int) ($info['pending_update_count'] ?? 0);
        $now = time();

        if ($url === '') {
            return [
                'status' => 'inactive',
                'score' => 0,
                'issues' => [[
                    'level' => 'warning',
                    'title' => 'No webhook is set',
                    'detail' => 'Telegram does not push updates to this bot. Set a webhook, or receive updates by polling with getUpdates.',
                ]],
            ];
        }

        if (! Str::startsWith($url, 'https://')) {
            $issues[] = ['level' => 'error', 'title' => 'Webhook is not HTTPS', 'detail' => 'Telegram only delivers webhooks over HTTPS.'];
        }

        if (! empty($config['url']) && rtrim($config['url'], '/') !== rtrim($url, '/')) {
            $issues[] = ['level' => 'warning', 'title' => 'URL differs from your config', 'detail' => "Telegram delivers to {$url}, but bot.connections.{$connection}.url is {$config['url']}."];
        }

        if ($lastError = $info['last_error_date'] ?? null) {
            $recent = $now - (int) $lastError < 3600;

            $issues[] = [
                'level' => $recent ? 'error' : 'warning',
                'title' => $recent ? 'Recent delivery error' : 'Past delivery error',
                'detail' => ($info['last_error_message'] ?? 'Unknown error').' ('.$this->ago((int) $lastError).')',
            ];
        }

        if ($syncError = $info['last_synchronization_error_date'] ?? null) {
            $issues[] = ['level' => 'warning', 'title' => 'Synchronization error', 'detail' => 'The Bot API server could not sync with Telegram '.$this->ago((int) $syncError).'.'];
        }

        $warning = (int) config('sentinel.webhook.pending_warning', 50);
        $critical = (int) config('sentinel.webhook.pending_critical', 500);

        if ($pending >= $critical) {
            $issues[] = ['level' => 'error', 'title' => "{$pending} updates waiting", 'detail' => 'Updates pile up faster than your bot answers them. Check the server and the webhook errors.'];
        } elseif ($pending >= $warning) {
            $issues[] = ['level' => 'warning', 'title' => "{$pending} updates waiting", 'detail' => 'Telegram is holding back updates for this bot.'];
        }

        if ($pending >= $warning && ($info['max_connections'] ?? 40) <= 10) {
            $issues[] = ['level' => 'info', 'title' => 'Few simultaneous connections', 'detail' => "max_connections is {$info['max_connections']}; raising it lets Telegram deliver updates in parallel."];
        }

        $configured = $config['allowed_updates'] ?? null;
        $current = $info['allowed_updates'] ?? null;

        if (is_array($configured) && ! in_array('*', $configured, true) && is_array($current) && array_diff($configured, $current) !== []) {
            $issues[] = ['level' => 'info', 'title' => 'Allowed updates differ', 'detail' => 'Your config allows '.implode(', ', array_diff($configured, $current)).' but the webhook does not receive them.'];
        }

        if (! empty($info['has_custom_certificate'])) {
            $issues[] = ['level' => 'info', 'title' => 'Self-signed certificate', 'detail' => 'The webhook uses a custom certificate you uploaded.'];
        }

        $score = 100;

        foreach ($issues as $issue) {
            $score -= match ($issue['level']) {
                'error' => 40,
                'warning' => 15,
                default => 0,
            };
        }

        $levels = array_column($issues, 'level');

        return [
            'status' => in_array('error', $levels, true) ? 'error' : (in_array('warning', $levels, true) ? 'warning' : 'healthy'),
            'score' => max(0, $score),
            'issues' => $issues,
        ];
    }

    /**
     * Get the profile of a bot: account, names, descriptions and commands.
     *
     * @param  string  $connection
     * @return array
     */
    public function profile(string $connection): array
    {
        $me = $this->me($connection);

        return [
            'me' => $me['result'] ?? null,
            'error' => ($me['ok'] ?? false) ? null : ($me['description'] ?? 'Unreachable'),
            'description' => $this->call($connection, 'getMyDescription')['result']['description'] ?? null,
            'short_description' => $this->call($connection, 'getMyShortDescription')['result']['short_description'] ?? null,
            'menu_button' => $this->call($connection, 'getChatMenuButton')['result'] ?? null,
            'commands' => $this->commands($connection),
        ];
    }

    /**
     * Get the commands registered with Telegram for the default scopes.
     *
     * @param  string  $connection
     * @return array<string, array<int, array{command: string, description: string}>>
     */
    public function commands(string $connection): array
    {
        $scopes = [];

        foreach (['default', 'all_private_chats', 'all_group_chats', 'all_chat_administrators'] as $scope) {
            $response = $this->call($connection, 'getMyCommands', ['scope' => ['type' => $scope]]);

            if (($response['ok'] ?? false) && ! empty($response['result'])) {
                $scopes[$scope] = $response['result'];
            }
        }

        return $scopes;
    }

    /**
     * Set the webhook of a connection.
     *
     * @param  string  $connection
     * @param  array  $options
     * @return array
     */
    public function setWebhook(string $connection, array $options): array
    {
        $config = $this->connections()[$connection] ?? [];
        $secret = config("bot.connections.{$connection}.secret_token");

        $allowed = $options['allowed_updates'] ?? $config['allowed_updates'] ?? null;

        if (is_array($allowed) && in_array('*', $allowed, true)) {
            $allowed = null;
        }

        return $this->call($connection, 'setWebhook', array_filter([
            'url' => $options['url'] ?? $config['url'] ?? null,
            'max_connections' => isset($options['max_connections']) ? (int) $options['max_connections'] : null,
            'allowed_updates' => is_array($allowed) ? array_values($allowed) : null,
            'drop_pending_updates' => ! empty($options['drop_pending_updates']) ? true : null,
            'secret_token' => $secret ?: null,
            'ip_address' => $options['ip_address'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Remove the webhook of a connection.
     *
     * @param  string  $connection
     * @param  bool  $dropPending
     * @return array
     */
    public function deleteWebhook(string $connection, bool $dropPending = false): array
    {
        return $this->call($connection, 'deleteWebhook', $dropPending ? ['drop_pending_updates' => true] : []);
    }

    /**
     * Describe how long ago a timestamp was.
     *
     * @param  int  $timestamp
     * @return string
     */
    protected function ago(int $timestamp): string
    {
        $seconds = max(0, time() - $timestamp);

        return match (true) {
            $seconds < 60 => $seconds.'s ago',
            $seconds < 3600 => intdiv($seconds, 60).'m ago',
            $seconds < 86400 => intdiv($seconds, 3600).'h ago',
            default => intdiv($seconds, 86400).'d ago',
        };
    }
}
