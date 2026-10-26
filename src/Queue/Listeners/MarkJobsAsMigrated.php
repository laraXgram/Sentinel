<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Contracts\JobRepository;
use LaraGram\Sentinel\Queue\Events\JobsMigrated;

class MarkJobsAsMigrated
{
    /**
     * The job repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\JobRepository
     */
    public $jobs;

    /**
     * Create a new listener instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\JobRepository  $jobs
     * @return void
     */
    public function __construct(JobRepository $jobs)
    {
        $this->jobs = $jobs;
    }

    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\JobsMigrated  $event
     * @return void
     */
    public function handle(JobsMigrated $event)
    {
        $this->jobs->migrated($event->connectionName, $event->queue, $event->payloads);
    }
}
