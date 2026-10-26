<?php

namespace LaraGram\Sentinel\Queue;

use LaraGram\Console\Process\Process;

class SystemProcessCounter
{
    /**
     * The base command to search for.
     *
     * @var string
     */
    public static $command = 'sentinel:queue:work';

    /**
     * Get the number of Sentinel workers for a given supervisor.
     *
     * @param  string  $name
     * @return int
     */
    public function get($name)
    {
        $process = Process::fromShellCommandline('exec ps aux | grep '.static::$command, null, ['COLUMNS' => '2000']);

        $process->run();

        return substr_count($process->getOutput(), 'supervisor='.$name);
    }
}
