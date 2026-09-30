<?php

namespace LaraGram\Sentinel;

use Closure;

trait AuthorizesRequests
{
    /**
     * The callback that should be used to authenticate Sentinel users.
     *
     * @var \Closure|null
     */
    public static $authUsing;

    /**
     * Register the Sentinel authentication callback.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function auth(Closure $callback)
    {
        static::$authUsing = $callback;

        return new static;
    }

    /**
     * Determine if the given request can access the Sentinel dashboard.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return bool
     */
    public static function check($request)
    {
        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }
}
