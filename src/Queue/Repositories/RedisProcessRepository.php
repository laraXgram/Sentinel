<?php

namespace LaraGram\Sentinel\Queue\Repositories;

use LaraGram\Tempora\TemporaImmutable;
use LaraGram\Contracts\Redis\Factory as RedisFactory;
use LaraGram\Sentinel\Queue\Contracts\ProcessRepository;

class RedisProcessRepository implements ProcessRepository
{
    use UsesClusterAwarePipeline;

    /**
     * The Redis connection instance.
     *
     * @var \LaraGram\Contracts\Redis\Factory
     */
    public $redis;

    /**
     * Create a new repository instance.
     *
     * @param  \LaraGram\Contracts\Redis\Factory  $redis
     * @return void
     */
    public function __construct(RedisFactory $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Get all of the orphan process IDs and the times they were observed.
     *
     * @param  string  $master
     * @return array
     */
    public function allOrphans($master)
    {
        return $this->connection()->hgetall(
            "{$master}:orphans"
        );
    }

    /**
     * Record the given process IDs as orphaned.
     *
     * @param  string  $master
     * @param  array  $processIds
     * @return void
     */
    public function orphaned($master, array $processIds)
    {
        $time = TemporaImmutable::now()->getTimestamp();

        $shouldRemove = array_diff($this->connection()->hkeys(
            $key = "{$master}:orphans"
        ), $processIds);

        if (! empty($shouldRemove)) {
            $this->connection()->hdel($key, ...$shouldRemove);
        }

        $this->pipeline(function ($pipe) use ($key, $time, $processIds) {
            foreach ($processIds as $processId) {
                $pipe->hsetnx($key, $processId, $time);
            }
        });
    }

    /**
     * Get the process IDs orphaned for at least the given number of seconds.
     *
     * @param  string  $master
     * @param  int  $seconds
     * @return array
     */
    public function orphanedFor($master, $seconds)
    {
        $expiresAt = TemporaImmutable::now()->getTimestamp() - $seconds;

        return collect($this->allOrphans($master))
            ->filter(fn ($recordedAt, $_) => $expiresAt > $recordedAt)
            ->keys()
            ->all();
    }

    /**
     * Remove the given process IDs from the orphan list.
     *
     * @param  string  $master
     * @param  array  $processIds
     * @return void
     */
    public function forgetOrphans($master, array $processIds)
    {
        $this->connection()->hdel(
            "{$master}:orphans", ...$processIds
        );
    }

    /**
     * Get the Redis connection instance.
     *
     * @return \LaraGram\Redis\Connections\Connection
     */
    protected function connection()
    {
        return $this->redis->connection('sentinel-queue');
    }
}
