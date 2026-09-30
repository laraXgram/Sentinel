<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Log\Events\MessageLogged;
use LaraGram\Sentinel\IncomingExceptionEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\ExceptionContext;
use LaraGram\Sentinel\Support\Redactor;
use Throwable;

class ExceptionWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(MessageLogged::class, [$this, 'recordException']);
    }

    /**
     * Record an exception was logged.
     *
     * @param  \LaraGram\Log\Events\MessageLogged  $event
     * @return void
     */
    public function recordException(MessageLogged $event)
    {
        if (! Sentinel::isRecording() || ! isset($event->context['exception']) || ! $event->context['exception'] instanceof Throwable) {
            return;
        }

        $exception = $event->context['exception'];

        Sentinel::count('exceptions');

        $location = ExceptionContext::relative($exception->getFile()).':'.$exception->getLine();

        $context = array_diff_key($event->context, ['exception' => true, 'sentinel' => true]);

        $entry = new IncomingExceptionEntry($exception, [
            'class' => get_class($exception),
            'file' => ExceptionContext::relative($exception->getFile()),
            'line' => $exception->getLine(),
            'message' => Redactor::string($exception->getMessage()),
            'code' => $exception->getCode(),
            'context' => $context !== [] ? Redactor::redact(json_decode(json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [], 'fields') : null,
            'trace' => array_map(function ($frame) {
                $frame['file'] = ExceptionContext::relative($frame['file'] ?? null);

                return $frame;
            }, ExceptionContext::trace($exception)),
            'line_preview' => ExceptionContext::get($exception),
            'previous' => $this->previous($exception),
            'update_type' => Sentinel::$context['update_type'] ?? null,
        ]);

        Sentinel::recordException($entry->tags(array_merge(
            ['exception:'.class_basename($exception)],
            (array) ($event->context['sentinel'] ?? [])
        )));

        if (Sentinel::$simulation === null) {
            Sentinel::metric('exception', json_encode([get_class($exception), $location]), time(), ['count', 'max']);
        }
    }

    /**
     * Describe the previous exceptions of an exception.
     *
     * @param  \Throwable  $exception
     * @return array<int, array>
     */
    protected function previous(Throwable $exception): array
    {
        $previous = [];

        while (($exception = $exception->getPrevious()) && count($previous) < 5) {
            $previous[] = [
                'class' => get_class($exception),
                'message' => Redactor::string($exception->getMessage()),
                'file' => ExceptionContext::relative($exception->getFile()),
                'line' => $exception->getLine(),
            ];
        }

        return $previous;
    }
}
