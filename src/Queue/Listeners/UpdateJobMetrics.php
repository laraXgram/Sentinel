<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Contracts\MetricsRepository;
use LaraGram\Sentinel\Queue\Events\JobDeleted;
use LaraGram\Sentinel\Queue\Stopwatch;

class UpdateJobMetrics
{
    /**
     * The metrics repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\MetricsRepository
     */
    public $metrics;

    /**
     * The stopwatch instance.
     *
     * @var \LaraGram\Sentinel\Queue\Stopwatch
     */
    public $watch;

    /**
     * Create a new listener instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MetricsRepository  $metrics
     * @param  \LaraGram\Sentinel\Queue\Stopwatch  $watch
     * @return void
     */
    public function __construct(MetricsRepository $metrics, Stopwatch $watch)
    {
        $this->watch = $watch;
        $this->metrics = $metrics;
    }

    /**
     * Stop gathering metrics for a job.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\JobDeleted  $event
     * @return void
     */
    public function handle(JobDeleted $event)
    {
        if ($event->job->hasFailed()) {
            return;
        }

        $time = $this->watch->check($id = $event->payload->id()) ?: 0;

        $this->metrics->incrementQueue(
            $event->job->getQueue(), $time
        );

        $this->metrics->incrementJob(
            $event->payload->displayName(), $time
        );

        $this->watch->forget($id);
    }
}
