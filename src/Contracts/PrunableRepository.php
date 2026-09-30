<?php

namespace LaraGram\Sentinel\Contracts;

use DateTimeInterface;

interface PrunableRepository
{
    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before, bool $keepExceptions = false): int;
}
