<?php

namespace LaraGram\Sentinel\Watchers;

abstract class Watcher
{
    /**
     * Create a new watcher instance.
     *
     * @param  array  $options  The configured watcher options.
     * @return void
     */
    public function __construct(
        public array $options = [],
    ) {
    }

    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    abstract public function register($app);

    /**
     * Get the elapsed milliseconds since the given hrtime.
     *
     * @param  int|float  $start
     * @return float
     */
    protected function since($start): float
    {
        return round((hrtime(true) - $start) / 1e6, 2);
    }
}
