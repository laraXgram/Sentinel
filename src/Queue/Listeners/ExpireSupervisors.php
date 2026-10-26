<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;
use LaraGram\Sentinel\Queue\Events\MasterSupervisorLooped;

class ExpireSupervisors
{
    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\MasterSupervisorLooped  $event
     * @return void
     */
    public function handle(MasterSupervisorLooped $event)
    {
        app(MasterSupervisorRepository::class)->flushExpired();

        app(SupervisorRepository::class)->flushExpired();
    }
}
