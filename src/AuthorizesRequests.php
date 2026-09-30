<?php

namespace LaraGram\Sentinel;

use Closure;
use LaraGram\Sentinel\Auth\Authenticator;

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
     * A request passes when it carries a Sentinel login session, or when the
     * callback registered with auth() allows it. With no login method set
     * up, the dashboard stays open in the local environment.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return bool
     */
    public static function check($request)
    {
        $auth = app(Authenticator::class);

        if ($auth->user($request) !== null) {
            return true;
        }

        if (static::$authUsing && (static::$authUsing)($request)) {
            return true;
        }

        return ! $auth->configured() && app()->environment('local');
    }

    /**
     * Get the admin logged in to the dashboard, if any.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return array|null
     */
    public static function user($request)
    {
        return app(Authenticator::class)->user($request);
    }
}
