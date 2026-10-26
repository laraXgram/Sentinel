<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:supervisors')]
class SupervisorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:supervisors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all of the supervisors';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\SupervisorRepository  $supervisors
     * @return void
     */
    public function handle(SupervisorRepository $supervisors)
    {
        $supervisors = $supervisors->all();

        if (empty($supervisors)) {
            return $this->components->info('No supervisors are running.');
        }

        $this->output->writeln('');

        $this->table([
            'Name', 'PID', 'Status', 'Workers', 'Balancing',
        ], collect($supervisors)->map(function ($supervisor) {
            return [
                $supervisor->name,
                $supervisor->pid,
                $supervisor->status,
                collect($supervisor->processes)->map(function ($count, $queue) {
                    return $queue.' ('.$count.')';
                })->implode(', '),
                $supervisor->options['balance'],
            ];
        })->all());

        $this->output->writeln('');
    }
}
