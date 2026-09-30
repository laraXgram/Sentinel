<?php

namespace LaraGram\Sentinel;

trait RegistersWatchers
{
    /**
     * The class names of the registered watchers.
     *
     * @var array<int, class-string>
     */
    protected static $watchers = [];

    /**
     * Determine if a given watcher has been registered.
     *
     * @param  string  $class
     * @return bool
     */
    public static function hasWatcher($class)
    {
        return in_array($class, static::$watchers);
    }

    /**
     * Register the configured Sentinel watchers.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function registerWatchers($app)
    {
        foreach (config('sentinel.watchers', []) as $key => $watcher) {
            if (is_string($key) && $watcher === false) {
                continue;
            }

            if (is_array($watcher) && ! ($watcher['enabled'] ?? true)) {
                continue;
            }

            $class = is_string($key) ? $key : $watcher;

            if (static::hasWatcher($class)) {
                continue;
            }

            $watcher = $app->make($class, [
                'options' => is_array($watcher) ? $watcher : [],
            ]);

            static::$watchers[] = $class;

            $watcher->register($app);
        }
    }
}
