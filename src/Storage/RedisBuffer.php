<?php

namespace LaraGram\Sentinel\Storage;

use LaraGram\Contracts\Redis\Factory as RedisFactory;

/**
 * Keeps the recorded batches in a Redis list until `sentinel:flush` writes
 * them to the database.
 *
 * Writing every update's metrics straight to the database makes concurrent
 * updates fight over the same aggregate rows; under a burst of updates that
 * means lock waits, deadlocks and a database that slows the whole bot down.
 * Pushing to a list costs one Redis command and never waits on a lock, and a
 * single flusher writes the batches together.
 */
class RedisBuffer
{
    /**
     * Takes up to ARGV[1] batches off the head of the list in one step, so
     * two flushers never write the same batch.
     */
    protected const PULL_SCRIPT = <<<'LUA'
        local batches = redis.call('LRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1)
        if #batches > 0 then
            redis.call('LTRIM', KEYS[1], #batches, -1)
        end
        return batches
        LUA;

    /**
     * Create a new Redis buffer.
     *
     * @param  \LaraGram\Contracts\Redis\Factory  $redis
     * @param  string|null  $connection
     * @param  string  $key
     * @param  int  $maxBatches  Older batches are dropped past this many; 0 keeps all.
     * @return void
     */
    public function __construct(
        protected RedisFactory $redis,
        protected ?string $connection = null,
        protected string $key = 'sentinel:buffer',
        protected int $maxBatches = 100000,
    ) {
    }

    /**
     * Get the Redis connection.
     *
     * @return \LaraGram\Redis\Connections\Connection
     */
    protected function connection()
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * Add a batch to the end of the buffer.
     *
     * @param  array{entries: array, metrics: array, values: array}  $batch
     * @return void
     */
    public function push(array $batch): void
    {
        $connection = $this->connection();

        $connection->rpush($this->key, json_encode(
            $batch, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ));

        // Without a running flusher the list would grow without end.
        if ($this->maxBatches > 0) {
            $connection->ltrim($this->key, -$this->maxBatches, -1);
        }
    }

    /**
     * Take up to `$count` batches off the head of the buffer.
     *
     * @param  int  $count
     * @return array<int, array{entries: array, metrics: array, values: array}>
     */
    public function pull(int $count): array
    {
        $batches = $this->connection()->eval(static::PULL_SCRIPT, 1, $this->key, max(1, $count));

        return array_values(array_filter(array_map(
            fn ($batch) => is_string($batch) ? json_decode($batch, true) : null,
            (array) $batches
        ), 'is_array'));
    }

    /**
     * Get the number of batches waiting in the buffer.
     *
     * @return int
     */
    public function size(): int
    {
        return (int) $this->connection()->llen($this->key);
    }
}
