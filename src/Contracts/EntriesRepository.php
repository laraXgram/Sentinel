<?php

namespace LaraGram\Sentinel\Contracts;

use LaraGram\Sentinel\EntryResult;
use LaraGram\Sentinel\Storage\EntryQueryOptions;
use LaraGram\Support\Collection;

interface EntriesRepository
{
    /**
     * Find the entry with the given ID.
     */
    public function find($id): EntryResult;

    /**
     * Find all of the entries matching the given options.
     *
     * @return \LaraGram\Support\Collection<int, \LaraGram\Sentinel\EntryResult>
     */
    public function get(?string $type, EntryQueryOptions $options): Collection;

    /**
     * Store the given entries.
     *
     * @param  \LaraGram\Support\Collection<int, \LaraGram\Sentinel\IncomingEntry>  $entries
     */
    public function store(Collection $entries): void;

    /**
     * Determine if any of the given tags are currently being monitored.
     */
    public function isMonitoring(array $tags): bool;

    /**
     * Get the list of tags currently being monitored.
     */
    public function monitoring(): array;

    /**
     * Begin monitoring the given list of tags.
     */
    public function monitor(array $tags): void;

    /**
     * Stop monitoring the given list of tags.
     */
    public function stopMonitoring(array $tags): void;

    /**
     * Load the monitored tags from storage.
     */
    public function loadMonitoredTags(): void;
}
