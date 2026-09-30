<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Contracts\PrunableRepository;

#[AsCommand(name: 'sentinel:prune')]
class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:prune
        {--hours= : The number of hours to retain Sentinel entries}
        {--keep-exceptions : Retain exception entries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune stale Sentinel entries and metrics';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Contracts\PrunableRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function handle(PrunableRepository $entries, MetricsRepository $metrics)
    {
        $hours = (int) ($this->option('hours') ?: config('sentinel.retention.entries', 48));

        $deleted = $entries->prune(now()->subHours($hours), (bool) $this->option('keep-exceptions'));

        $metrics->trim();

        $this->components->info("{$deleted} entries pruned.");
    }
}
