<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Events\SupervisorLooped;

class PruneTerminatingProcesses
{
    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\SupervisorLooped  $event
     * @return void
     */
    public function handle(SupervisorLooped $event)
    {
        $event->supervisor->pruneTerminatingProcesses();
    }
}
