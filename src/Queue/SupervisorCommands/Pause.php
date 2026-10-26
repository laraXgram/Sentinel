<?php

namespace LaraGram\Sentinel\Queue\SupervisorCommands;

use LaraGram\Sentinel\Queue\Contracts\Pausable;

class Pause
{
    /**
     * Process the command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\Pausable  $pausable
     * @return void
     */
    public function process(Pausable $pausable)
    {
        $pausable->pause();
    }
}
