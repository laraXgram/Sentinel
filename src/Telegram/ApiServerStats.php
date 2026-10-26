<?php

namespace LaraGram\Sentinel\Telegram;

use LaraGram\Support\Facades\Http;
use Throwable;

/**
 * Reads the statistics page of a local Bot API server (telegram-bot-api, or
 * the LaraGram API Server, started with --http-stat-port).
 *
 * The page is plain text: one "key<TAB>value" line per figure, with four
 * values for the windowed ones (since start, last 5 seconds, last minute,
 * last hour; rates per second), then a block per bot after a blank line.
 */
class ApiServerStats
{
    /**
     * The windows of the windowed figures, as the DURATION line names them.
     *
     * @var array<int, string>
     */
    public const WINDOWS = ['inf', '5sec', '1min', '1hour'];

    /**
     * Get the URL of the statistics page, or null when none is configured.
     *
     * @return string|null
     */
    public function url(): ?string
    {
        if (filled($url = config('sentinel.api_server.stats_url'))) {
            return rtrim((string) $url, '/');
        }

        $port = config('bot.api_server.stat.port');

        if (blank($port)) {
            return null;
        }

        $ip = config('bot.api_server.stat.ip') ?: config('bot.api_server.ip') ?: '127.0.0.1';

        return 'http://'.(in_array($ip, ['0.0.0.0', '::'], true) ? '127.0.0.1' : $ip).':'.$port;
    }

    /**
     * Fetch and parse the statistics.
     *
     * @return array{configured: bool, ok: bool, error: string|null, latency: float|null, server: array, bots: array<int, array>}
     */
    public function fetch(): array
    {
        $result = ['configured' => false, 'ok' => false, 'error' => null, 'latency' => null, 'server' => [], 'bots' => []];

        if (($url = $this->url()) === null) {
            return $result;
        }

        $result['configured'] = true;

        try {
            $startedAt = microtime(true);

            $response = Http::timeout((int) config('sentinel.api_server.timeout', 5))->get($url);

            $result['latency'] = round((microtime(true) - $startedAt) * 1000, 2);

            if (! $response->successful()) {
                return ['error' => "The statistics page answered with HTTP {$response->status()}."] + $result;
            }

            $body = trim($response->body());

            if ($body === 'Closing') {
                return ['error' => 'The Bot API server is shutting down.'] + $result;
            }

            return ['ok' => true] + static::parse($body) + $result;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()] + $result;
        }
    }

    /**
     * Parse the text of the statistics page.
     *
     * @param  string  $text
     * @return array{server: array<string, mixed>, bots: array<int, array<string, mixed>>}
     */
    public static function parse(string $text): array
    {
        $windows = static::WINDOWS;
        $server = [];
        $bots = [];

        foreach (preg_split("/\r?\n\s*\r?\n/", trim($text)) as $block) {
            $fields = [];

            foreach (preg_split("/\r?\n/", $block) as $line) {
                $parts = explode("\t", $line);
                $key = trim((string) array_shift($parts));

                if ($key === '') {
                    continue;
                }

                if ($key === 'DURATION') {
                    $windows = $parts;

                    continue;
                }

                $values = array_map(static::value(...), $parts);

                $fields[$key] = count($values) > 1
                    ? array_combine(array_slice(array_pad($windows, count($values), ''), 0, count($values)), $values)
                    : ($values[0] ?? null);
            }

            if (isset($fields['id'])) {
                $bots[] = static::bot($fields);
            } elseif ($fields !== []) {
                $server = $fields + $server;
            }
        }

        return ['server' => $server, 'bots' => $bots];
    }

    /**
     * Turn a value of the page into a number where it is one.
     *
     * Sizes ("12345KB") become bytes and percentages become floats.
     *
     * @param  string  $value
     * @return int|float|string|null
     */
    protected static function value(string $value): int|float|string|null
    {
        $value = trim($value);

        return match (true) {
            $value === 'UNKNOWN' => null,
            (bool) preg_match('/^(\d+)(B|KB|MB|GB)$/', $value, $size) => (int) $size[1] * match ($size[2]) {
                'KB' => 1 << 10,
                'MB' => 1 << 20,
                'GB' => 1 << 30,
                default => 1,
            },
            (bool) preg_match('/^-?\d+(\.\d+)?%$/', $value) => (float) rtrim($value, '%'),
            (bool) preg_match('/^-?\d+$/', $value) => (int) $value,
            is_numeric($value) => (float) $value,
            default => $value,
        };
    }

    /**
     * Mask the token of a bot block and name its bot connection.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected static function bot(array $fields): array
    {
        $token = (string) ($fields['token'] ?? '');

        $fields['connection'] = collect(config('bot.connections', []))
            ->filter(fn ($connection) => $token !== '' && hash_equals((string) ($connection['token'] ?? ''), $token))
            ->keys()
            ->first();

        $fields['token'] = $token === '' ? null : strtok($token, ':').':'.str_repeat('•', 8);

        return $fields;
    }
}
