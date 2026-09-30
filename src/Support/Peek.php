<?php

namespace LaraGram\Sentinel\Support;

use Closure;

class Peek
{
    /**
     * Read a property of an object, even when it is not public.
     *
     * @param  object|null  $object
     * @param  string  $property
     * @param  mixed  $default
     * @return mixed
     */
    public static function property(?object $object, string $property, mixed $default = null): mixed
    {
        if ($object === null) {
            return $default;
        }

        try {
            return Closure::bind(
                fn () => property_exists($this, $property) ? $this->{$property} : $default,
                $object,
                get_class($object)
            )();
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Describe a closure by the file and line it was defined on.
     *
     * @param  mixed  $callable
     * @return string|null
     */
    public static function location(mixed $callable): ?string
    {
        try {
            if ($callable instanceof Closure) {
                $reflection = new \ReflectionFunction($callable);

                return ExceptionContext::relative($reflection->getFileName()).':'.$reflection->getStartLine();
            }
        } catch (\Throwable) {
            //
        }

        return null;
    }
}
