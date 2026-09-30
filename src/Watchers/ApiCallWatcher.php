<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Request\Events\ApiCallCompleted;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\ExceptionContext;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Support\Str;

class ApiCallWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        if (! class_exists(ApiCallCompleted::class)) {
            return;
        }

        $app['events']->listen(ApiCallCompleted::class, [$this, 'recordApiCall']);
    }

    /**
     * Record a Telegram API call.
     *
     * @param  \LaraGram\Request\Events\ApiCallCompleted  $event
     * @return void
     */
    public function recordApiCall(ApiCallCompleted $event)
    {
        if (! Sentinel::isRecording() || in_array($event->method, $this->options['ignore_methods'] ?? [], true)) {
            return;
        }

        $response = $this->normalize($event->response);
        $ok = $event->successful() && ($response['ok'] ?? true) !== false;
        $errorCode = $ok ? null : ($response['error_code'] ?? ($event->exception ? $event->exception->getCode() : null));
        $description = $ok ? null : ($response['description'] ?? $event->exception?->getMessage());
        $retryAfter = $response['parameters']['retry_after'] ?? null;
        $chatId = $event->parameters['chat_id'] ?? null;
        $connection = $event->connection ?? Sentinel::currentConnection();
        $slow = $event->duration >= ($this->options['slow'] ?? 1500);
        $network = ! $ok && ($response['network'] ?? false);
        $blocked = (int) $errorCode === 403 && is_string($description) && Str::contains($description, ['blocked', 'deactivated', 'kicked']);

        Sentinel::count('api_calls');
        Sentinel::count('api_time', $event->duration);

        if (! $ok) {
            Sentinel::count('api_errors');
        }

        $caller = ExceptionContext::caller();

        Sentinel::recordApiCall(IncomingEntry::make([
            'method' => $event->method,
            'parameters' => Redactor::redact($event->parameters),
            'ok' => $ok,
            'error_code' => $errorCode,
            'description' => $description !== null ? Redactor::string((string) $description) : null,
            'retry_after' => $retryAfter,
            'migrate_to_chat_id' => $response['parameters']['migrate_to_chat_id'] ?? null,
            'result' => Redactor::limit(
                Redactor::redact(['result' => $response['result'] ?? null])['result'],
                (int) ($this->options['response_size_limit'] ?? 64)
            ),
            'duration' => round($event->duration, 2),
            'intercepted' => $event->intercepted,
            'network_error' => $network,
            'exception' => $event->exception ? get_class($event->exception) : null,
            'caller' => $caller ? ExceptionContext::relative($caller['file']).':'.$caller['line'] : null,
        ])->connection($connection)->tags(array_values(array_filter([
            'method:'.$event->method,
            $chatId !== null && is_scalar($chatId) ? 'target:'.$chatId : null,
            $ok ? null : 'failed',
            $errorCode ? 'error:'.$errorCode : null,
            $retryAfter ? 'flood' : null,
            $blocked ? 'blocked' : null,
            $network ? 'network' : null,
            $slow ? 'slow' : null,
            $event->intercepted ? 'intercepted' : null,
        ]))));

        if ($event->intercepted) {
            return;
        }

        Sentinel::metric('api_call', $event->method, $event->duration, ['count', 'avg', 'max'], $connection);

        if (! $ok) {
            Sentinel::metric('api_error', json_encode([$event->method, (int) $errorCode, Str::limit((string) $description, 160)], JSON_UNESCAPED_UNICODE), null, ['count'], $connection);
        }

        if ($retryAfter) {
            Sentinel::metric('flood_wait', $event->method, (float) $retryAfter, ['count', 'max', 'sum'], $connection);
        }

        if ($blocked && $chatId !== null && is_scalar($chatId)) {
            Sentinel::metric('blocked', (string) $chatId, null, ['count'], $connection);
        }

        if ($slow) {
            Sentinel::metric('slow_api_call', $event->method, $event->duration, ['count', 'max'], $connection);
        }
    }

    /**
     * Normalize a response into an array.
     *
     * @param  mixed  $response
     * @return array
     */
    protected function normalize(mixed $response): array
    {
        if ($response === null) {
            return [];
        }

        if (is_array($response)) {
            return $response;
        }

        if ($response instanceof \LaraGram\Laraquest\Response) {
            return rescue(fn () => $response->toArray(full: true), [], false);
        }

        if (is_object($response)) {
            return rescue(fn () => json_decode(json_encode($response), true) ?: [], [], false);
        }

        if (is_string($response)) {
            return json_decode($response, true) ?: [];
        }

        return ['ok' => true, 'result' => $response];
    }
}
