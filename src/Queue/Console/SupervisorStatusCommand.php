<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Support\Str;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;
use LaraGram\Sentinel\Queue\MasterSupervisor;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:supervisor-status')]
class SupervisorStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:supervisor-status
                            {name : The name of the supervisor}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the status for a given supervisor';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\SupervisorRepository  $supervisors
     * @return int|void
     */
    public function handle(SupervisorRepository $supervisors)
    {
        $name = $this->argument('name');

        $supervisorStatus = optional(collect($supervisors->all())->first(function ($supervisor) use ($name) {
            return Str::startsWith($supervisor->name, MasterSupervisor::basename()) &&
                Str::endsWith($supervisor->name, $name);
        }))->status;

        if (is_null($supervisorStatus)) {
            $this->components->error('Unable to find a supervisor with this name.');

            return 1;
        }

        $this->components->info("{$name} is {$supervisorStatus}");
    }
}
