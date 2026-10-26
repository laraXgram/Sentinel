<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Stopwatch;

class ForgetJobTimer
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
     * @param  \LaraGram\Queue\Events\JobExceptionOccurred|\LaraGram\Queue\Events\JobFailed  $event
     * @return void
     */
    public function handle($event)
    {
        $this->watch->forget($event->job->getJobId());
    }
}
