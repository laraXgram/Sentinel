<?php

namespace LaraGram\Sentinel\Contracts;

use LaraGram\Support\Collection;

interface MetricsRepository
{
    /**
     * Store the given metrics and values.
     *
     * @param  array<int, array{type: string, key: string, value: float|int|null, timestamp: int, connection: string, aggregates: array<int, string>}>  $metrics
     * @param  array<int, array{type: string, key: string, value: mixed, timestamp: int}>  $values
     */
    public function store(array $metrics, array $values = []): void;

    /**
     * Get the charted buckets of the given types.
     *
     * @return \LaraGram\Support\Collection<string, \LaraGram\Support\Collection<string, array<int, float|null>>>
     */
    public function graph(array $types, string $aggregate, int $period, ?string $connection = null): Collection;

    /**
     * Get the charted buckets of the given types, summed across their keys.
     *
     * @return \LaraGram\Support\Collection<string, array<int, float>>
     */
    public function graphTotals(array $types, string $aggregate, int $period, ?string $connection = null): Collection;

    /**
     * Count the distinct keys of a type over the period.
     */
    public function distinct(string $type, int $period, ?string $connection = null, int $offset = 0): int;

    /**
     * Store a single value right away.
     */
    public function set(string $type, string $key, mixed $value): void;

    /**
     * Get the aggregated keys of a type over the period.
     *
     * @return \LaraGram\Support\Collection<int, object>
     */
    public function aggregate(string $type, array|string $aggregates, int $period, ?string $orderBy = null, string $direction = 'desc', int $limit = 25, ?string $connection = null): Collection;

    /**
     * Get the aggregated totals of the given types over the period.
     *
     * @return \LaraGram\Support\Collection<string, float>
     */
    public function aggregateTotal(array|string $types, string $aggregate, int $period, ?string $connection = null, int $offset = 0): Collection;

    /**
     * Get the stored values of a type.
     *
     * @return \LaraGram\Support\Collection<string, object>
     */
    public function values(string $type, ?array $keys = null): Collection;

    /**
     * Trim the stored data outside of the retention windows.
     */
    public function trim(): void;
}
