<?php

namespace LaraGram\Sentinel\Queue\MasterSupervisorCommands;

use LaraGram\Sentinel\Queue\MasterSupervisor;
use LaraGram\Sentinel\Queue\SupervisorOptions;
use LaraGram\Sentinel\Queue\SupervisorProcess;
use LaraGram\Console\Process\Process;

class AddSupervisor
{
    /**
     * Process the command.
     *
     * @param  \LaraGram\Sentinel\Queue\MasterSupervisor  $master
     * @param  array  $options
     * @return void
     */
    public function process(MasterSupervisor $master, array $options)
    {
        $options = SupervisorOptions::fromArray($options);

        $master->supervisors[] = new SupervisorProcess(
            $options, $this->createProcess($master, $options), function ($type, $line) use ($master) {
                $master->output($type, $line);
            }
        );
    }

    /**
     * Create the Symfony process instance.
     *
     * @param  \LaraGram\Sentinel\Queue\MasterSupervisor  $master
     * @param  \LaraGram\Sentinel\Queue\SupervisorOptions  $options
     * @return \LaraGram\Console\Process\Process
     */
    protected function createProcess(MasterSupervisor $master, SupervisorOptions $options)
    {
        $command = $options->toSupervisorCommand();

        return Process::fromShellCommandline($command, $options->directory ?? base_path())
                    ->setTimeout(null)
                    ->disableOutput();
    }
}
