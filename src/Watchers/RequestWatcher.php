<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Foundation\Http\Events\RequestHandled;
use LaraGram\Http\JsonResponse;
use LaraGram\Http\RedirectResponse;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Support\Str;

class RequestWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(RequestHandled::class, [$this, 'recordRequest']);
    }

    /**
     * Record an incoming web request.
     *
     * @param  \LaraGram\Foundation\Http\Events\RequestHandled  $event
     * @return void
     */
    public function recordRequest(RequestHandled $event)
    {
        $path = trim(config('sentinel.path', 'sentinel'), '/');

        if (! Sentinel::isRecording() || $event->request->is($path, $path.'/*')) {
            return;
        }

        $startTime = defined('LARAGRAM_START') ? LARAGRAM_START : $event->request->server('REQUEST_TIME_FLOAT');
        $duration = $startTime ? floor((microtime(true) - $startTime) * 1000) : null;
        $route = $event->request->route();
        $status = $event->response->getStatusCode();

        Sentinel::recordRequest(IncomingEntry::make([
            'ip_address' => $event->request->ip(),
            'uri' => str_replace($event->request->root(), '', $event->request->fullUrl()) ?: '/',
            'method' => $event->request->method(),
            'controller_action' => $route ? rescue(fn () => $route->getActionName(), null, false) : null,
            'middleware' => $route ? rescue(fn () => array_values(array_filter($route->gatherMiddleware(), 'is_string')), [], false) : [],
            'headers' => Redactor::headers($event->request->headers->all()),
            'payload' => Redactor::redact($event->request->all(), 'fields'),
            'response_status' => $status,
            'response' => $this->response($event->response),
            'duration' => $duration,
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ])->tags(array_filter([
            'status:'.$status,
            $status >= 500 ? 'failed' : null,
            $duration !== null && $duration >= ($this->options['slow'] ?? 1000) ? 'slow' : null,
        ])));

        Sentinel::metric('http_request', $event->request->method().' '.($route ? '/'.ltrim(rescue(fn () => $route->uri(), $event->request->path(), false), '/') : '/'.$event->request->path()), (float) $duration, ['count', 'avg', 'max'], '');
    }

    /**
     * Format the given response object.
     *
     * @param  mixed  $response
     * @return array|string
     */
    protected function response($response)
    {
        $content = $response->getContent();

        if (is_string($content)) {
            $decoded = json_decode($content, true);

            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return Redactor::limit(Redactor::redact($decoded, 'fields'), (int) ($this->options['size_limit'] ?? 64));
            }

            if (Str::startsWith(strtolower((string) $response->headers->get('Content-Type')), 'text/plain')) {
                return Redactor::limit($content, (int) ($this->options['size_limit'] ?? 64));
            }
        }

        if ($response instanceof RedirectResponse) {
            return 'Redirected to '.$response->getTargetUrl();
        }

        if ($response instanceof JsonResponse) {
            return Redactor::limit($response->getData(true), (int) ($this->options['size_limit'] ?? 64));
        }

        return 'HTML Response';
    }
}
