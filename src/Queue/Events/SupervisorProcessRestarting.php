<?php

namespace LaraGram\Sentinel\Queue\Events;

use LaraGram\Sentinel\Queue\SupervisorProcess;

class SupervisorProcessRestarting
{
    /**
     * The supervisor process instance.
     *
     * @var \LaraGram\Sentinel\Queue\SupervisorProcess
     */
    public $process;

    /**
     * Create a new event instance.
     *
     * @param  \LaraGram\Sentinel\Queue\SupervisorProcess  $process
     * @return void
     */
    public function __construct(SupervisorProcess $process)
    {
        $this->process = $process;
    }
}
