<?php

namespace LaraGram\Sentinel\Queue\Exceptions;

use LaraGram\Foundation\Http\Exceptions\HttpException;

class ForbiddenException extends HttpException
{
    /**
     * Create a new exception instance.
     *
     * @return static
     */
    public static function make()
    {
        return new static(403);
    }
}
