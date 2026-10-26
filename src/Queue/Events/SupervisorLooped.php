<?php

namespace LaraGram\Sentinel\Queue\Events;

use LaraGram\Sentinel\Queue\Supervisor;

class SupervisorLooped
{
    /**
     * The supervisor instance.
     *
     * @var \LaraGram\Sentinel\Queue\Supervisor
     */
    public $supervisor;

    /**
     * Create a new event instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Supervisor  $supervisor
     * @return void
     */
    public function __construct(Supervisor $supervisor)
    {
        $this->supervisor = $supervisor;
    }
}
