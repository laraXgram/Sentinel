<?php

namespace LaraGram\Sentinel\Queue\Events;

use LaraGram\Sentinel\Queue\Supervisor;

class SupervisorOutOfMemory
{
    /**
     * The supervisor instance.
     *
     * @var \LaraGram\Sentinel\Queue\Supervisor
     */
    public $supervisor;

    /**
     * The memory usage that exceeded the allowable limit.
     *
     * @var int|float
     */
    public $memoryUsage;

    /**
     * Create a new event instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Supervisor  $supervisor
     * @return void
     */
    public function __construct(Supervisor $supervisor)
    {
        $this->supervisor = $supervisor;
    }

    /**
     * Get the memory usage that triggered the event.
     *
     * @return int|float
     */
    public function getMemoryUsage()
    {
        return $this->memoryUsage ?? $this->supervisor->memoryUsage();
    }

    /**
     * Set the memory usage that was recorded when the event was dispatched.
     *
     * @param  int|float  $memoryUsage
     * @return $this
     */
    public function setMemoryUsage($memoryUsage)
    {
        $this->memoryUsage = $memoryUsage;

        return $this;
    }
}
