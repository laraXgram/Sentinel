<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Log\Events\MessageLogged;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Redactor;
use Throwable;

class LogWatcher extends Watcher
{
    /**
     * The available log level priorities.
     *
     * @var array<string, int>
     */
    protected const PRIORITIES = [
        'debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300,
        'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600,
    ];

    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(MessageLogged::class, [$this, 'recordLog']);
    }

    /**
     * Record a message was logged.
     *
     * @param  \LaraGram\Log\Events\MessageLogged  $event
     * @return void
     */
    public function recordLog(MessageLogged $event)
    {
        if (! Sentinel::isRecording() || ($event->context['exception'] ?? null) instanceof Throwable) {
            return;
        }

        $minimum = static::PRIORITIES[strtolower($this->options['level'] ?? 'debug')] ?? 100;

        if ((static::PRIORITIES[strtolower((string) $event->level)] ?? 100) < $minimum) {
            return;
        }

        Sentinel::recordLog(IncomingEntry::make([
            'level' => $event->level,
            'message' => Redactor::string((string) $event->message),
            'context' => Redactor::redact(json_decode(json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [], 'fields'),
        ])->tags(['level:'.$event->level]));

        Sentinel::metric('log', (string) $event->level, null, ['count']);
    }
}
