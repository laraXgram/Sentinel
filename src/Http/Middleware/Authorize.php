<?php

namespace LaraGram\Sentinel\Http\Middleware;

use LaraGram\Sentinel\Sentinel;

class Authorize
{
    /**
     * The routes reachable without logging in.
     *
     * @var array<int, string>
     */
    protected const PUBLIC_ROUTES = ['sentinel.asset', 'sentinel.login', 'sentinel.login.*', 'sentinel.logout'];

    /**
     * Handle the incoming request.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \Closure  $next
     * @return \LaraGram\Http\Response
     */
    public function handle($request, $next)
    {
        if ($request->routeIs(...static::PUBLIC_ROUTES) || Sentinel::check($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('*/api/*')) {
            return response()->json([
                'message' => 'Your Sentinel session has ended. Log in again.',
                'login' => route('sentinel.login'),
            ], 401);
        }

        return redirect()->route('sentinel.login');
    }
}
