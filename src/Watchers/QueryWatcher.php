<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Database\Events\QueryExecuted;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\ExceptionContext;

class QueryWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    /**
     * Record a query was executed.
     *
     * @param  \LaraGram\Database\Events\QueryExecuted  $event
     * @return void
     */
    public function recordQuery(QueryExecuted $event)
    {
        if (! Sentinel::isRecording()) {
            return;
        }

        $time = (float) $event->time;

        Sentinel::count('queries');
        Sentinel::count('query_time', $time);

        $caller = ExceptionContext::caller($this->options['ignore_paths'] ?? []);

        if ($caller === null && ($this->options['ignore_packages'] ?? true)) {
            $caller = ['file' => 'vendor', 'line' => 0];
        }

        $slow = $time >= ($this->options['slow'] ?? 100);
        $location = $caller ? ExceptionContext::relative($caller['file']).':'.$caller['line'] : null;

        Sentinel::recordQuery(IncomingEntry::make([
            'connection' => $event->connectionName,
            'bindings' => [],
            'sql' => $this->replaceBindings($event),
            'time' => number_format($time, 2, '.', ''),
            'slow' => $slow,
            'file' => $caller ? ExceptionContext::relative($caller['file']) : null,
            'line' => $caller['line'] ?? null,
            'hash' => $this->familyHash($event),
        ])->withFamilyHash($this->familyHash($event))->tags($slow ? ['slow'] : []));

        Sentinel::metric('query', $event->connectionName, $time, ['count', 'avg'], '');

        if ($slow) {
            Sentinel::metric('slow_query', json_encode([$event->sql, $location]), $time, ['count', 'max'], '');
        }
    }

    /**
     * Calculate the family look-up hash for the query event.
     *
     * @param  \LaraGram\Database\Events\QueryExecuted  $event
     * @return string
     */
    public function familyHash($event)
    {
        return md5($event->sql);
    }

    /**
     * Format the given bindings to strings.
     *
     * @param  \LaraGram\Database\Events\QueryExecuted  $event
     * @return array
     */
    protected function formatBindings($event)
    {
        return $event->connection->prepareBindings($event->bindings);
    }

    /**
     * Replace the placeholders with the actual bindings.
     *
     * @param  \LaraGram\Database\Events\QueryExecuted  $event
     * @return string
     */
    public function replaceBindings($event)
    {
        $sql = $event->sql;

        foreach ($this->formatBindings($event) as $key => $binding) {
            $regex = is_numeric($key)
                ? "/\?(?=(?:[^'\\\\']*'[^'\\\\']*')*[^'\\\\']*$)/"
                : "/:{$key}(?=(?:[^'\\\\']*'[^'\\\\']*')*[^'\\\\']*$)/";

            if ($binding === null) {
                $binding = 'null';
            } elseif (is_bool($binding)) {
                $binding = $binding ? '1' : '0';
            } elseif (is_int($binding) || is_float($binding)) {
                $binding = (string) $binding;
            } elseif (! is_string($binding)) {
                $binding = '[binary]';
            } else {
                $binding = mb_strlen($binding) > 500 ? '[long string]' : $this->quoteStringBinding($event, $binding);
            }

            $sql = preg_replace($regex, addcslashes($binding, '$\\'), $sql, 1);
        }

        return $sql;
    }

    /**
     * Add quotes to string bindings.
     *
     * @param  \LaraGram\Database\Events\QueryExecuted  $event
     * @param  string  $binding
     * @return string
     */
    protected function quoteStringBinding($event, $binding)
    {
        try {
            $pdo = $event->connection->getPdo();

            if ($pdo instanceof \PDO) {
                return $pdo->quote($binding);
            }
        } catch (\Throwable) {
            //
        }

        return "'".str_replace("'", "''", $binding)."'";
    }
}
