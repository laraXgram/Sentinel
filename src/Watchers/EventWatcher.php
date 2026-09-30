<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Support\Str;

class EventWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen('*', [$this, 'recordEvent']);
    }

    /**
     * Record an event was fired.
     *
     * @param  string  $eventName
     * @param  array  $payload
     * @return void
     */
    public function recordEvent($eventName, $payload)
    {
        if (! Sentinel::isRecording() || $this->shouldIgnore($eventName)) {
            return;
        }

        Sentinel::recordEvent(IncomingEntry::make([
            'name' => $eventName,
            'payload' => $this->payload($payload),
        ]));
    }

    /**
     * Describe the payload of an event.
     *
     * @param  array  $payload
     * @return array
     */
    protected function payload(array $payload): array
    {
        $event = $payload[0] ?? null;

        if (! is_object($event)) {
            return [];
        }

        $properties = [];

        foreach ((new \ReflectionObject($event))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isInitialized($event)) {
                continue;
            }

            $value = $property->getValue($event);

            $properties[$property->getName()] = is_object($value) ? '['.get_class($value).']' : (is_array($value) ? '[array]' : $value);
        }

        return $properties;
    }

    /**
     * Determine if the event should be ignored.
     *
     * @param  string  $eventName
     * @return bool
     */
    protected function shouldIgnore($eventName)
    {
        return Str::is(array_merge([
            'LaraGram\*', 'eloquent*', 'bootstrapped*', 'bootstrapping*', 'creating*', 'composing*',
        ], $this->options['ignore'] ?? []), $eventName);
    }
}
