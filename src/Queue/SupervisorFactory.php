<?php

namespace LaraGram\Sentinel\Queue;

class SupervisorFactory
{
    /**
     * Create a new supervisor instance.
     *
     * @param  \LaraGram\Sentinel\Queue\SupervisorOptions  $options
     * @return \LaraGram\Sentinel\Queue\Supervisor
     */
    public function make(SupervisorOptions $options)
    {
        return new Supervisor($options);
    }
}
