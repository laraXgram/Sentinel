<?php

namespace LaraGram\Sentinel\Queue;

use Exception;
use LaraGram\Redis\Connections\Connection;

/**
 * The Redis connection the queue supervisor keeps its jobs, metrics and
 * supervisors in (`sentinel-queue`), prefixed with `sentinel.queue.prefix`.
 */
class RedisStore
{
    /**
     * Configure the Redis connection that stores the queue supervisor's data.
     *
     * @param  string  $connection
     * @return void
     *
     * @throws \Exception
     */
    public static function use($connection)
    {
        if (! is_null($config = config("database.redis.clusters.{$connection}"))) {
            static::configureClusterConnection($config);
        } elseif (! is_null($config = config("database.redis.{$connection}"))) {
            static::configureStandaloneConnection($config);
        } else {
            throw new Exception("Redis connection [{$connection}] has not been configured.");
        }
    }

    /**
     * Configure the Sentinel Redis connection for a cluster.
     *
     * @param  array  $config
     * @return void
     */
    protected static function configureClusterConnection(array $config)
    {
        if (! static::supportsClustering()) {
            return static::configureStandaloneConnection($config[0]);
        }

        $prefix = static::ensureHashTaggedPrefix(
            config('sentinel.queue.prefix') ?: 'sentinel_queue:'
        );

        config(['sentinel.queue.prefix' => $prefix]);

        $config['options']['prefix'] = $prefix;

        config(['database.redis.clusters.sentinel-queue' => $config]);
    }

    /**
     * Configure the Sentinel Redis connection for a standalone server.
     *
     * @param  array  $config
     * @return void
     */
    protected static function configureStandaloneConnection(array $config)
    {
        $prefix = config('sentinel.queue.prefix') ?: 'sentinel_queue:';

        $config['options']['prefix'] = $prefix;

        config(['sentinel.queue.prefix' => $prefix]);
        config(['database.redis.sentinel-queue' => $config]);
    }

    /**
     * Ensure the given prefix contains a Redis Cluster hash tag.
     *
     * @param  string  $prefix
     * @return string
     */
    protected static function ensureHashTaggedPrefix(string $prefix): string
    {
        return Connection::hasHashTag($prefix) ? $prefix : '{'.$prefix.'}';
    }

    /**
     * Determine if the framework supports Redis Cluster.
     *
     * @return bool
     */
    protected static function supportsClustering()
    {
        return method_exists(Connection::class, 'hasHashTag');
    }
}
