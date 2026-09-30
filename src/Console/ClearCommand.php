<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;

#[AsCommand(name: 'sentinel:clear')]
class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:clear {--metrics : Also clear the recorded metrics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all Sentinel entries';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function handle(EntriesRepository $entries, MetricsRepository $metrics)
    {
        $entries->clear();

        if ($this->option('metrics')) {
            $metrics->clear();
        }

        $this->components->info('Sentinel entries cleared!');
    }
}
