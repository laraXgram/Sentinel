<?php

namespace LaraGram\Sentinel\Http\Middleware;

use LaraGram\Sentinel\Sentinel;

class Authorize
{
    /**
     * Handle the incoming request.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \Closure  $next
     * @return \LaraGram\Http\Response
     */
    public function handle($request, $next)
    {
        return Sentinel::check($request) ? $next($request) : abort(403);
    }
}
