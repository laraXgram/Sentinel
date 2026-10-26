<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:status')]
class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the current status of the queue supervisor';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository  $masterSupervisorRepository
     * @return int
     */
    public function handle(MasterSupervisorRepository $masterSupervisorRepository)
    {
        if (! $masters = $masterSupervisorRepository->all()) {
            $this->components->error('The queue supervisor is inactive.');

            return 2;
        }

        if (collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        })) {
            $this->components->warn('The queue supervisor is paused.');

            return 1;
        }

        $this->components->info('The queue supervisor is running.');

        return 0;
    }
}
