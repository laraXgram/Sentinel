<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Events\MasterSupervisorLooped;
use LaraGram\Sentinel\Queue\Events\MasterSupervisorOutOfMemory;

class MonitorMasterSupervisorMemory
{
    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\MasterSupervisorLooped  $event
     * @return void
     */
    public function handle(MasterSupervisorLooped $event)
    {
        $master = $event->master;

        $memoryLimit = config('sentinel.queue.memory_limit', 64);

        if ($master->memoryUsage() > $memoryLimit) {
            event(new MasterSupervisorOutOfMemory($master));

            $master->output('error', 'Memory limit exceeded: Using '.ceil($master->memoryUsage()).'/'.$memoryLimit.'MB. Consider increasing sentinel.queue.memory_limit.');

            $master->terminate(12);
        }
    }
}
