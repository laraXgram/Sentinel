<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Contracts\Events\Dispatcher;
use LaraGram\Queue\Events\JobFailed as LaravelJobFailed;
use LaraGram\Queue\Jobs\RedisJob;
use LaraGram\Sentinel\Queue\Events\JobFailed;

class MarshalFailedEvent
{
    /**
     * The event dispatcher implementation.
     *
     * @var \LaraGram\Contracts\Events\Dispatcher
     */
    public $events;

    /**
     * Create a new listener instance.
     *
     * @param  \LaraGram\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    /**
     * Handle the event.
     *
     * @param  \LaraGram\Queue\Events\JobFailed  $event
     * @return void
     */
    public function handle(LaravelJobFailed $event)
    {
        if (! $event->job instanceof RedisJob) {
            return;
        }

        $this->events->dispatch((new JobFailed(
            $event->exception, $event->job, $event->job->getReservedJob()
        ))->connection($event->connectionName)->queue($event->job->getQueue()));
    }
}
