<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Sentinel\Queue\Contracts\MetricsRepository;
use LaraGram\Sentinel\Queue\Lock;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:snapshot')]
class SnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:snapshot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store a snapshot of the queue metrics';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Lock  $lock
     * @param  \LaraGram\Sentinel\Queue\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function handle(Lock $lock, MetricsRepository $metrics)
    {
        if ($lock->get('metrics:snapshot', config('sentinel.queue.metrics.snapshot_lock', 300) - 30)) {
            $metrics->snapshot();

            $this->components->info('Metrics snapshot stored successfully.');
        }
    }
}
