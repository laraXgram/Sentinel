<?php

namespace LaraGram\Sentinel\Support;

use Throwable;

class ExceptionContext
{
    /**
     * Get the lines of code around the line that threw the exception.
     *
     * @param  \Throwable  $exception
     * @return array<int, string>
     */
    public static function get(Throwable $exception): array
    {
        return static::lines($exception->getFile(), $exception->getLine());
    }

    /**
     * Get the lines of a file around a given line.
     *
     * @param  string  $file
     * @param  int  $line
     * @param  int  $padding
     * @return array<int, string>
     */
    public static function lines(string $file, int $line, int $padding = 9): array
    {
        if (str_contains($file, "eval()'d code") || ! is_file($file) || ! is_readable($file)) {
            return [];
        }

        $lines = [];
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return [];
        }

        $number = 0;

        while (($content = fgets($handle)) !== false) {
            $number++;

            if ($number < $line - $padding) {
                continue;
            }

            if ($number > $line + $padding) {
                break;
            }

            $lines[$number] = rtrim($content, "\r\n");
        }

        fclose($handle);

        return $lines;
    }

    /**
     * Get the trace of an exception in a storable form.
     *
     * @param  \Throwable  $exception
     * @param  int  $limit
     * @return array<int, array>
     */
    public static function trace(Throwable $exception, int $limit = 60): array
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];

        foreach (array_slice($exception->getTrace(), 0, $limit) as $frame) {
            $frames[] = array_filter([
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'function' => $frame['function'] ?? null,
            ], fn ($value) => $value !== null);
        }

        return $frames;
    }

    /**
     * Get a path relative to the application's base path.
     *
     * @param  string|null  $file
     * @return string|null
     */
    public static function relative(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Find the first application frame of the current call stack.
     *
     * @param  array<int, string>  $ignore  Path fragments that do not belong to the application.
     * @return array{file: string, line: int}|null
     */
    public static function caller(array $ignore = []): ?array
    {
        $ignore = array_merge([DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR, 'Sentinel'.DIRECTORY_SEPARATOR.'src'], $ignore);
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            if (! isset($frame['file']) || ! str_starts_with($frame['file'], $base)) {
                continue;
            }

            foreach ($ignore as $fragment) {
                if (str_contains($frame['file'], $fragment)) {
                    continue 2;
                }
            }

            if (str_ends_with($frame['file'], 'laragram') || str_ends_with($frame['file'], 'server.php')) {
                continue;
            }

            return ['file' => $frame['file'], 'line' => $frame['line'] ?? 0];
        }

        return null;
    }
}
