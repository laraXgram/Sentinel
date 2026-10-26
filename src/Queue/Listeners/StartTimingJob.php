<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Events\JobReserved;
use LaraGram\Sentinel\Queue\Stopwatch;

class StartTimingJob
{
    /**
     * The stopwatch instance.
     *
     * @var \LaraGram\Sentinel\Queue\Stopwatch
     */
    public $watch;

    /**
     * Create a new listener instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Stopwatch  $watch
     * @return void
     */
    public function __construct(Stopwatch $watch)
    {
        $this->watch = $watch;
    }

    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\JobReserved  $event
     * @return void
     */
    public function handle(JobReserved $event)
    {
        $this->watch->start($event->payload->id());
    }
}
