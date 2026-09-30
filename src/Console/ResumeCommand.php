<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;

#[AsCommand(name: 'sentinel:resume')]
class ResumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:resume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resume Sentinel recording';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        cache()->forget('sentinel:pause-recording');

        $this->components->info('Sentinel recording resumed.');
    }
}
