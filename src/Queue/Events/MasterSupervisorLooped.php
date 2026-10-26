<?php

namespace LaraGram\Sentinel\Queue\Events;

use LaraGram\Sentinel\Queue\MasterSupervisor;

class MasterSupervisorLooped
{
    /**
     * The master supervisor instance.
     *
     * @var \LaraGram\Sentinel\Queue\MasterSupervisor
     */
    public $master;

    /**
     * Create a new event instance.
     *
     * @param  \LaraGram\Sentinel\Queue\MasterSupervisor  $master
     * @return void
     */
    public function __construct(MasterSupervisor $master)
    {
        $this->master = $master;
    }
}
