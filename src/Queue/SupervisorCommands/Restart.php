<?php

namespace LaraGram\Sentinel\Queue\SupervisorCommands;

use LaraGram\Sentinel\Queue\Contracts\Restartable;

class Restart
{
    /**
     * Process the command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\Restartable  $restartable
     * @return void
     */
    public function process(Restartable $restartable)
    {
        $restartable->restart();
    }
}
