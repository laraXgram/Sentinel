<?php

namespace LaraGram\Sentinel\Contracts;

interface ClearableRepository
{
    /**
     * Clear all of the recorded entries and metrics.
     */
    public function clear(): void;
}
