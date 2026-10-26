<?php

namespace LaraGram\Sentinel\Queue\SupervisorCommands;

use LaraGram\Sentinel\Queue\Contracts\Terminable;

class Terminate
{
    /**
     * Process the command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\Terminable  $terminable
     * @param  array  $options
     * @return void
     */
    public function process(Terminable $terminable, array $options)
    {
        $terminable->terminate($options['status'] ?? 0);
    }
}
