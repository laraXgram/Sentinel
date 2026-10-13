<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Storage\RedisBuffer;
use Throwable;

#[AsCommand(name: 'sentinel:flush')]
class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:flush
        {--once : Write what is buffered now and exit}
        {--seconds=55 : Keep flushing for this many seconds; 0 runs until stopped}
        {--sleep=3 : Seconds to wait when the buffer is empty}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Write the batches buffered in Redis to the database';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Storage\RedisBuffer  $buffer
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return int
     */
    public function handle(RedisBuffer $buffer, EntriesRepository $entries, MetricsRepository $metrics)
    {
        $seconds = max(0, (int) $this->option('seconds'));
        $sleep = max(1, (int) $this->option('sleep'));
        $size = max(1, (int) config('sentinel.storage.redis.flush_size', 500));
        $until = $seconds > 0 ? microtime(true) + $seconds : null;

        $written = 0;

        do {
            $batches = $buffer->pull($size);

            if ($batches !== []) {
                $written += $this->write($batches, $entries, $metrics);

                // A full pull means more is waiting: write it before sleeping.
                if (count($batches) === $size) {
                    continue;
                }
            }

            if ($this->option('once') || ($until !== null && microtime(true) + $sleep >= $until)) {
                break;
            }

            sleep($sleep);
        } while ($until === null || microtime(true) < $until);

        rescue(fn () => $metrics->trim(), report: false);

        if ($this->output->isVerbose()) {
            $this->components->info("{$written} batches written.");
        }

        return self::SUCCESS;
    }

    /**
     * Write the pulled batches together.
     *
     * @param  array<int, array{entries: array, metrics: array, values: array}>  $batches
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return int
     */
    protected function write(array $batches, EntriesRepository $entries, MetricsRepository $metrics): int
    {
        $queue = collect();
        $metricRows = [];
        $values = [];

        foreach ($batches as $batch) {
            foreach ($batch['entries'] ?? [] as $row) {
                $queue->push(IncomingEntry::fromBuffer($row));
            }

            array_push($metricRows, ...($batch['metrics'] ?? []));

            foreach ($batch['values'] ?? [] as $value) {
                $values = $this->mergeValue($values, $value);
            }
        }

        try {
            Sentinel::write($entries, $metrics, $queue, $metricRows, array_values($values));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        return count($batches);
    }

    /**
     * Keep the latest value of a type and key, still counting the updates
     * of the ones it replaces.
     *
     * @param  array<string, array>  $values
     * @param  array  $value
     * @return array<string, array>
     */
    protected function mergeValue(array $values, array $value): array
    {
        $id = $value['type']."\0".$value['key'];
        $previous = $values[$id] ?? null;

        if (is_array($previous['value'] ?? null) && is_array($value['value'])) {
            $value['value']['updates'] = ($previous['value']['updates'] ?? 1) + ($value['value']['updates'] ?? 1);
        }

        $values[$id] = $value;

        return $values;
    }
}
