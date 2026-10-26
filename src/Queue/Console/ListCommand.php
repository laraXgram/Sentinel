<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:list')]
class ListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all of the deployed machines';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $masters = $masters->all();

        if (empty($masters)) {
            return $this->components->info('No machines are running.');
        }

        $this->output->writeln('');

        $this->table([
            'Name', 'PID', 'Supervisors', 'Status',
        ], collect($masters)->map(function ($master) {
            return [
                $master->name,
                $master->pid,
                $master->supervisors ? collect($master->supervisors)->map(function ($supervisor) {
                    return explode(':', $supervisor, 2)[1];
                })->implode(', ') : 'None',
                $master->status,
            ];
        })->all());

        $this->output->writeln('');
    }
}
