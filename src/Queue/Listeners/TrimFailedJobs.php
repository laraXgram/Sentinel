<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Tempora\TemporaImmutable;
use LaraGram\Sentinel\Queue\Contracts\JobRepository;
use LaraGram\Sentinel\Queue\Events\MasterSupervisorLooped;

class TrimFailedJobs
{
    /**
     * The last time the recent jobs were trimmed.
     *
     * @var \LaraGram\Tempora\TemporaImmutable
     */
    public $lastTrimmed;

    /**
     * How many minutes to wait in between each trim.
     *
     * @var int
     */
    public $frequency = 5040;

    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\MasterSupervisorLooped  $event
     * @return void
     */
    public function handle(MasterSupervisorLooped $event)
    {
        if (! isset($this->lastTrimmed)) {
            $this->frequency = max(1, intdiv(
                config('sentinel.queue.trim.failed', 10080), 12
            ));

            $this->lastTrimmed = TemporaImmutable::now()->subMinutes($this->frequency + 1);
        }

        if ($this->lastTrimmed->lte(TemporaImmutable::now()->subMinutes($this->frequency))) {
            app(JobRepository::class)->trimFailedJobs();

            $this->lastTrimmed = TemporaImmutable::now();
        }
    }
}
