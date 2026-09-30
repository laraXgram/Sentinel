<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Routing\Controller as BaseController;
use LaraGram\Sentinel\Sentinel;

abstract class Controller extends BaseController
{
    /**
     * Get the metric period requested, in minutes.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return int
     */
    protected function period(Request $request): int
    {
        $period = (int) $request->query('period', 60);

        return in_array($period, Sentinel::PERIODS, true) ? $period : 60;
    }

    /**
     * Get the bot connection requested, if any.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return string|null
     */
    protected function connection(Request $request): ?string
    {
        $connection = $request->query('connection');

        return is_string($connection) && $connection !== '' && $connection !== 'all' ? $connection : null;
    }

    /**
     * Decode a JSON metric key.
     *
     * @param  string  $key
     * @return array
     */
    protected function decodeKey(string $key): array
    {
        $decoded = json_decode($key, true);

        return is_array($decoded) ? $decoded : [$key];
    }
}
