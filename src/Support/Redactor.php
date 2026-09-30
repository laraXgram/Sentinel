<?php

namespace LaraGram\Sentinel\Support;

use CURLFile;
use CURLStringFile;

class Redactor
{
    /**
     * The text replacing hidden values.
     */
    public const MASK = '********';

    /**
     * The cached lists of hidden keys.
     *
     * @var array<string, array<int, string>>
     */
    protected static array $hidden = [];

    /**
     * Forget the cached lists of hidden keys.
     *
     * @return void
     */
    public static function flush(): void
    {
        static::$hidden = [];
    }

    /**
     * Get the hidden keys of a group, lowercased.
     *
     * @param  string  $group
     * @return array<int, string>
     */
    protected static function hidden(string $group): array
    {
        return static::$hidden[$group] ??= array_map('strtolower', (array) config("sentinel.hidden.{$group}", []));
    }

    /**
     * Redact API parameters or request fields.
     *
     * @param  array  $data
     * @param  string  $group
     * @param  int  $depth
     * @return array
     */
    public static function redact(array $data, string $group = 'parameters', int $depth = 0): array
    {
        $hidden = static::hidden($group);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $hidden, true)) {
                $data[$key] = static::MASK;
            } elseif ($value instanceof CURLStringFile) {
                $data[$key] = '[file: '.($value->postname ?: 'upload').', '.static::bytes(strlen($value->data)).']';
            } elseif ($value instanceof CURLFile) {
                $data[$key] = '[file: '.($value->getPostFilename() ?: basename($value->getFilename())).']';
            } elseif (is_array($value)) {
                $data[$key] = $depth > 12 ? '[nested]' : static::redact($value, $group, $depth + 1);
            } elseif (is_object($value)) {
                $data[$key] = $depth > 12 ? '[nested]' : static::redact(json_decode(json_encode($value), true) ?? [], $group, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = static::string($value);
            }
        }

        return $data;
    }

    /**
     * Redact HTTP headers.
     *
     * @param  array  $headers
     * @return array
     */
    public static function headers(array $headers): array
    {
        $hidden = static::hidden('headers');

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $hidden, true)) {
                $headers[$key] = static::MASK;
            } else {
                $headers[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $headers;
    }

    /**
     * Mask bot tokens inside a string.
     *
     * @param  string  $value
     * @return string
     */
    public static function string(string $value): string
    {
        if (! str_contains($value, ':')) {
            return $value;
        }

        return preg_replace('/\b(\d{5,12}):[A-Za-z0-9_-]{30,}\b/', '$1:'.static::MASK, $value) ?? $value;
    }

    /**
     * Mask a bot token for display.
     *
     * @param  string|null  $token
     * @return string|null
     */
    public static function token(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        [$id] = explode(':', $token, 2);

        return $id.':'.static::MASK.substr($token, -4);
    }

    /**
     * Truncate a decoded payload to roughly the given number of kilobytes.
     *
     * @param  mixed  $payload
     * @param  int  $kilobytes
     * @return mixed
     */
    public static function limit(mixed $payload, int $kilobytes): mixed
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false || strlen($json) <= $kilobytes * 1024) {
            return $payload;
        }

        return 'Purged By Sentinel: '.static::bytes(strlen($json)).' payload is larger than '.$kilobytes.'KB';
    }

    /**
     * Format a number of bytes.
     *
     * @param  int  $bytes
     * @return string
     */
    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).'MB',
            $bytes >= 1024 => round($bytes / 1024, 1).'KB',
            default => $bytes.'B',
        };
    }
}
