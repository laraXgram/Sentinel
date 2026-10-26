<?php

namespace LaraGram\Sentinel\Queue\Contracts;

interface Restartable
{
    /**
     * Restart the process.
     *
     * @return void
     */
    public function restart();
}
