<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;

#[AsCommand(name: 'sentinel:pause')]
class PauseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:pause';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause Sentinel recording';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        cache()->forever('sentinel:pause-recording', true);

        $this->components->info('Sentinel recording paused.');
    }
}
