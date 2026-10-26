<?php

namespace LaraGram\Sentinel\Queue\SupervisorCommands;

use LaraGram\Sentinel\Queue\Supervisor;

class Balance
{
    /**
     * Process the command.
     *
     * @param  \LaraGram\Sentinel\Queue\Supervisor  $supervisor
     * @param  array  $options
     * @return void
     */
    public function process(Supervisor $supervisor, array $options)
    {
        $supervisor->balance($options);
    }
}
