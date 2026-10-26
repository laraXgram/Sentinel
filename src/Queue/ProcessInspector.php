<?php

namespace LaraGram\Sentinel\Queue;

use LaraGram\Support\Arr;
use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;

class ProcessInspector
{
    /**
     * The command executor.
     *
     * @var \LaraGram\Sentinel\Queue\Exec
     */
    public $exec;

    /**
     * Create a new process inspector instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Exec  $exec
     * @return void
     */
    public function __construct(Exec $exec)
    {
        $this->exec = $exec;
    }

    /**
     * Get the IDs of all Sentinel processes running on the system.
     *
     * @return array
     */
    public function current()
    {
        return array_diff(
            $this->exec->run('pgrep -f [s]entinel:queue'),
            $this->exec->run('pgrep -f sentinel:queue:purge')
        );
    }

    /**
     * Get an array of running Sentinel processes that can't be accounted for.
     *
     * @return array
     */
    public function orphaned()
    {
        return array_diff($this->current(), $this->monitoring());
    }

    /**
     * Get all of the process IDs Sentinel is actively monitoring.
     *
     * @return array
     */
    public function monitoring()
    {
        return collect(app(SupervisorRepository::class)->all())
            ->pluck('pid')
            ->pipe(function ($processes) {
                $processes->each(function ($process) use (&$processes) {
                    $processes = $processes->merge($this->exec->run("pgrep -P {$process}"));
                });

                return $processes;
            })
            ->merge(Arr::pluck(app(MasterSupervisorRepository::class)->all(), 'pid'))
            ->all();
    }
}
