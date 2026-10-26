<?php

namespace LaraGram\Sentinel\Queue\Connectors;

use LaraGram\Queue\Connectors\RedisConnector as BaseConnector;
use LaraGram\Support\Arr;
use LaraGram\Sentinel\Queue\RedisQueue;

class RedisConnector extends BaseConnector
{
    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \LaraGram\Sentinel\Queue\RedisQueue
     */
    public function connect(array $config)
    {
        return new RedisQueue(
            $this->redis, $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null)
        );
    }
}
