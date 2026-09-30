<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Foundation\Bot\Events\RequestHandled;
use LaraGram\Listening\Events\Listening;
use LaraGram\Listening\Events\ListenMatched;
use LaraGram\Request\Request as BotRequest;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Peek;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Sentinel\Support\UpdateParser;
use LaraGram\Support\Str;

class UpdateWatcher extends Watcher
{
    /**
     * The listens matched by the update being handled.
     *
     * @var array<int, array>
     */
    protected array $matched = [];

    /**
     * When handling the update being watched started.
     *
     * @var int|float|null
     */
    protected $startedAt = null;

    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        if ($app->bound('request') && $app['request'] instanceof BotRequest && Sentinel::runningBotUpdate()) {
            $this->begin($app['request']);
        }

        $app['events']->listen(Listening::class, function (Listening $event) {
            if (! $event->request instanceof BotRequest) {
                return;
            }

            if ($this->startedAt === null || Sentinel::$context === []) {
                $this->begin($event->request);
            } else {
                Sentinel::$context['connection'] = rescue(fn () => $event->request->botConnection(), null, false)
                    ?? Sentinel::$context['connection'] ?? null;
            }
        });

        $app['events']->listen(ListenMatched::class, [$this, 'recordMatchedListen']);

        $app['events']->listen(RequestHandled::class, [$this, 'recordUpdate']);
    }

    /**
     * Start watching an update.
     *
     * @param  \LaraGram\Request\Request  $request
     * @return void
     */
    protected function begin(BotRequest $request): void
    {
        $this->matched = [];
        $this->startedAt = hrtime(true);

        Sentinel::beginUpdate($request);
    }

    /**
     * Remember a listen matched by the update.
     *
     * @param  \LaraGram\Listening\Events\ListenMatched  $event
     * @return void
     */
    public function recordMatchedListen(ListenMatched $event)
    {
        if (! $event->request instanceof BotRequest) {
            return;
        }

        $listen = $event->listen;

        $this->matched[] = [
            'key' => static::listenKey($listen),
            'name' => rescue(fn () => $listen->getName(), null, false),
            'action' => static::listenAction($listen),
            'middleware' => rescue(fn () => array_values(array_filter($listen->gatherMiddleware(), 'is_string')), [], false),
        ];
    }

    /**
     * Record the handled update.
     *
     * @param  \LaraGram\Foundation\Bot\Events\RequestHandled  $event
     * @return void
     */
    public function recordUpdate(RequestHandled $event)
    {
        if (! Sentinel::isRecording()) {
            return;
        }

        $update = rescue(fn () => $event->request->toArray(), [], false);
        $summary = UpdateParser::summarize($update);

        if (in_array($summary['type'], $this->options['ignore_types'] ?? [], true)) {
            $this->reset();

            return;
        }

        if (Sentinel::$context === []) {
            Sentinel::beginUpdate($event->request);
        }

        $connection = rescue(fn () => $event->request->botConnection(), null, false) ?? Sentinel::currentConnection();

        Sentinel::$context['connection'] = $connection;

        $duration = $this->startedAt !== null
            ? $this->since($this->startedAt)
            : (defined('LARAGRAM_START') ? round((microtime(true) - LARAGRAM_START) * 1000, 2) : 0.0);

        $counters = Sentinel::$counters;
        $failed = ($counters['exceptions'] ?? 0) > 0;
        $handled = $this->matched !== [];
        $slow = $duration >= ($this->options['slow'] ?? 1000);

        $status = match (true) {
            $failed => 'failed',
            ! $handled => 'unhandled',
            default => 'handled',
        };

        Sentinel::recordUpdate(IncomingEntry::make([
            'update_id' => $summary['update_id'],
            'type' => $summary['type'],
            'kind' => $summary['kind'],
            'command' => $summary['command'],
            'chat' => $summary['chat'],
            'user' => $summary['user'],
            'text' => $summary['text'] !== null ? Redactor::string($summary['text']) : null,
            'status' => $status,
            'listens' => $this->matched,
            'duration' => $duration,
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'api_calls' => $counters['api_calls'] ?? 0,
            'api_time' => round($counters['api_time'] ?? 0, 2),
            'api_errors' => $counters['api_errors'] ?? 0,
            'queries' => $counters['queries'] ?? 0,
            'query_time' => round($counters['query_time'] ?? 0, 2),
            'exceptions' => $counters['exceptions'] ?? 0,
            'response' => $this->response($event->response),
            'simulation' => Sentinel::$simulation,
            'update' => Redactor::redact($update),
        ])->connection($connection)->tags($this->tags($summary, $status, $slow)));

        $this->recordMetrics($summary, $duration, $handled, $slow, $connection);

        $this->reset();
    }

    /**
     * Record the metrics of the handled update.
     *
     * @param  array  $summary
     * @param  float  $duration
     * @param  bool  $handled
     * @param  bool  $slow
     * @param  string|null  $connection
     * @return void
     */
    protected function recordMetrics(array $summary, float $duration, bool $handled, bool $slow, ?string $connection): void
    {
        if (Sentinel::$simulation !== null) {
            return;
        }

        Sentinel::metric('update', $summary['type'], $duration, ['count', 'avg', 'max'], $connection);
        Sentinel::metric('update_kind', $summary['kind'], null, ['count'], $connection);

        if ($summary['command'] !== null) {
            Sentinel::metric('bot_command', $summary['command'], $duration, ['count', 'avg'], $connection);
        }

        if (! $handled) {
            Sentinel::metric('unhandled', $summary['type'].($summary['kind'] !== $summary['type'] ? ':'.$summary['kind'] : ''), null, ['count'], $connection);
        }

        if ($slow) {
            Sentinel::metric('slow_update', $summary['type'], $duration, ['count', 'max'], $connection);
        }

        foreach ($this->matched as $listen) {
            Sentinel::metric('listen', $listen['key'], $duration, ['count', 'avg', 'max'], $connection);
        }

        if ($summary['chat'] !== null) {
            Sentinel::metric('chat', (string) $summary['chat']['id'], null, ['count'], $connection);

            Sentinel::value('chat', (string) $summary['chat']['id'], $summary['chat'] + [
                'connection' => $connection,
                'last_seen' => time(),
                'updates' => 1,
            ]);
        }

        if ($summary['user'] !== null) {
            Sentinel::metric('user', (string) $summary['user']['id'], null, ['count'], $connection);

            Sentinel::value('user', (string) $summary['user']['id'], $summary['user'] + [
                'connection' => $connection,
                'last_seen' => time(),
                'last_update' => $summary['type'],
                'updates' => 1,
                'type' => 'user',
            ]);
        }
    }

    /**
     * Get the tags of the handled update.
     *
     * @param  array  $summary
     * @param  string  $status
     * @param  bool  $slow
     * @return array<int, string>
     */
    protected function tags(array $summary, string $status, bool $slow): array
    {
        return array_values(array_filter([
            'update:'.$summary['type'],
            'kind:'.$summary['kind'],
            $summary['command'] ? 'command:'.$summary['command'] : null,
            $summary['type'] === 'callback_query' && $summary['text'] !== null ? 'callback:'.Str::before($summary['text'], ':') : null,
            $summary['chat']['type'] ?? null ? 'chat-type:'.$summary['chat']['type'] : null,
            $status !== 'handled' ? $status : null,
            $slow ? 'slow' : null,
            ...array_map(fn ($listen) => $listen['name'] ? 'listen:'.$listen['name'] : null, $this->matched),
        ]));
    }

    /**
     * Describe the response of the handled update.
     *
     * @param  mixed  $response
     * @return string|null
     */
    protected function response($response): ?string
    {
        $content = rescue(function () use ($response) {
            if (is_object($response) && method_exists($response, 'getContent')) {
                return $response->getContent();
            }

            return Peek::property($response, 'content');
        }, null, false);

        if (! is_scalar($content) || in_array($content, ['', 'null', '[]', '{}'], true)) {
            return null;
        }

        return Str::limit(Redactor::string((string) $content), 1000);
    }

    /**
     * Forget the state of the handled update.
     *
     * @return void
     */
    protected function reset(): void
    {
        $this->matched = [];
        $this->startedAt = null;
    }

    /**
     * Get the key identifying a listen in metrics.
     *
     * @param  mixed  $listen
     * @return string
     */
    public static function listenKey($listen): string
    {
        $methods = rescue(fn () => implode('|', (array) $listen->methods()), '', false);
        $pattern = rescue(fn () => (string) $listen->pattern(), '', false);

        return trim($methods.' '.$pattern) ?: 'unknown';
    }

    /**
     * Describe the action handling a listen.
     *
     * @param  mixed  $listen
     * @return string
     */
    public static function listenAction($listen): string
    {
        $action = rescue(fn () => $listen->getActionName(), 'Closure', false);

        if ($action === 'Closure') {
            $location = Peek::location(rescue(fn () => $listen->getAction('uses'), null, false));

            return $location ? 'Closure ('.$location.')' : 'Closure';
        }

        return (string) $action;
    }
}
