<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Sentinel\Queue\Contracts\MetricsRepository;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:clear-metrics')]
class ClearMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:clear-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete metrics for all jobs and queues';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function handle(MetricsRepository $metrics)
    {
        $metrics->clear();

        $this->components->info('Metrics cleared successfully.');
    }
}
