<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Cache\Events\CacheHit;
use LaraGram\Cache\Events\CacheMissed;
use LaraGram\Cache\Events\KeyForgotten;
use LaraGram\Cache\Events\KeyWritten;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Support\Str;

class CacheWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(CacheHit::class, fn ($event) => $this->recordCacheEvent($event, 'hit'));
        $app['events']->listen(CacheMissed::class, fn ($event) => $this->recordCacheEvent($event, 'missed'));
        $app['events']->listen(KeyWritten::class, fn ($event) => $this->recordCacheEvent($event, 'set'));
        $app['events']->listen(KeyForgotten::class, fn ($event) => $this->recordCacheEvent($event, 'forget'));
    }

    /**
     * Record a cache event.
     *
     * @param  object  $event
     * @param  string  $type
     * @return void
     */
    protected function recordCacheEvent($event, string $type)
    {
        if (! Sentinel::isRecording() || Str::is($this->options['ignore'] ?? [], (string) $event->key)) {
            return;
        }

        $hidden = Str::is($this->options['hidden'] ?? [], (string) $event->key);

        Sentinel::recordCache(IncomingEntry::make(array_filter([
            'type' => $type,
            'key' => $event->key,
            'store' => $event->storeName ?? null,
            'value' => in_array($type, ['hit', 'set'], true) ? ($hidden ? Redactor::MASK : $this->value($event->value ?? null)) : null,
            'expiration' => $event->seconds ?? null,
        ], fn ($value) => $value !== null)));

        if ($type === 'hit' || $type === 'missed') {
            Sentinel::metric('cache', $type, null, ['count'], '');
        }
    }

    /**
     * Describe a cached value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function value(mixed $value): mixed
    {
        if (is_object($value)) {
            return '['.get_class($value).']';
        }

        return Redactor::limit($value, 8);
    }
}
