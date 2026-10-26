<?php

namespace LaraGram\Sentinel\Queue\Events;

use LaraGram\Sentinel\Queue\WorkerProcess;

class UnableToLaunchProcess
{
    /**
     * The worker process instance.
     *
     * @var \LaraGram\Sentinel\Queue\WorkerProcess
     */
    public $process;

    /**
     * Create a new event instance.
     *
     * @param  \LaraGram\Sentinel\Queue\WorkerProcess  $process
     * @return void
     */
    public function __construct(WorkerProcess $process)
    {
        $this->process = $process;
    }
}
