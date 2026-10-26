<?php

namespace LaraGram\Sentinel\Queue\Events;

class JobFailed extends RedisEvent
{
    /**
     * The exception that caused the failure.
     *
     * @var \Exception
     */
    public $exception;

    /**
     * The queue job instance.
     *
     * @var \LaraGram\Queue\Jobs\Job
     */
    public $job;

    /**
     * Create a new event instance.
     *
     * @param  \Exception  $exception
     * @param  \LaraGram\Queue\Jobs\Job  $job
     * @param  string  $payload
     * @return void
     */
    public function __construct($exception, $job, $payload)
    {
        $this->job = $job;
        $this->exception = $exception;

        parent::__construct($payload);
    }
}
