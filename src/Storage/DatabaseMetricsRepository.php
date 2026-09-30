<?php

namespace LaraGram\Sentinel\Storage;

use LaraGram\Database\ConnectionResolverInterface;
use LaraGram\Database\Query\Expression;
use LaraGram\Sentinel\Contracts\ClearableRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Support\Collection;
use RuntimeException;

class DatabaseMetricsRepository implements MetricsRepository, ClearableRepository
{
    /**
     * The value types that remember when their key was first seen.
     *
     * @var array<int, string>
     */
    protected const TRACKED_VALUES = ['user', 'chat'];

    /**
     * Create a new database metrics repository.
     *
     * @param  \LaraGram\Database\ConnectionResolverInterface  $db
     * @param  string|null  $connection
     * @param  int  $chunkSize
     * @param  int  $retentionDays
     * @return void
     */
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
        protected int $chunkSize = 1000,
        protected int $retentionDays = 7,
    ) {
    }

    /**
     * Get the database connection.
     *
     * @return \LaraGram\Database\Connection
     */
    protected function connection()
    {
        return $this->db->connection($this->connection);
    }

    /**
     * Wrap a column name for the current grammar.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrap(string $value): string
    {
        return $this->connection()->getQueryGrammar()->wrap($value);
    }

    /**
     * {@inheritdoc}
     */
    public function store(array $metrics, array $values = []): void
    {
        $values = $this->trackFirstSeen($values, $metrics);

        $rows = [];

        foreach ($metrics as $metric) {
            $hash = md5($metric['key']);

            foreach (Sentinel::PERIODS as $period) {
                $bucket = intdiv($metric['timestamp'], $period) * $period;

                foreach ($metric['aggregates'] as $aggregate) {
                    $value = $aggregate === 'count' ? 1.0 : (float) ($metric['value'] ?? 0);
                    $id = implode("\0", [$bucket, $period, $metric['type'], $metric['connection'], $aggregate, $hash]);

                    if (! isset($rows[$aggregate][$id])) {
                        $rows[$aggregate][$id] = [
                            'bucket' => $bucket,
                            'period' => $period,
                            'type' => $metric['type'],
                            'connection' => $metric['connection'],
                            'key' => $metric['key'],
                            'key_hash' => $hash,
                            'aggregate' => $aggregate,
                            'value' => $value,
                            'count' => 1,
                        ];

                        continue;
                    }

                    $row = &$rows[$aggregate][$id];

                    $row['value'] = match ($aggregate) {
                        'count', 'sum' => $row['value'] + $value,
                        'max' => max($row['value'], $value),
                        'min' => min($row['value'], $value),
                        'avg' => ($row['value'] * $row['count'] + $value) / ($row['count'] + 1),
                        default => throw new RuntimeException("Unsupported aggregate [{$aggregate}]."),
                    };

                    $row['count']++;

                    unset($row);
                }
            }
        }

        foreach ($rows as $aggregate => $aggregateRows) {
            foreach (array_chunk(array_values($aggregateRows), $this->chunkSize) as $chunk) {
                $this->upsert($aggregate, $chunk);
            }
        }

        if ($values !== []) {
            $this->connection()->table('sentinel_values')->upsert(array_map(fn ($value) => [
                'timestamp' => $value['timestamp'],
                'type' => $value['type'],
                'key' => $value['key'],
                'key_hash' => md5($value['key']),
                'value' => json_encode($value['value'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ], $values), ['type', 'key_hash'], ['timestamp', 'value']);
        }
    }

    /**
     * Keep the first time users and chats were seen, recording the new ones.
     *
     * @param  array  $values
     * @param  array  $metrics
     * @return array
     */
    protected function trackFirstSeen(array $values, array &$metrics): array
    {
        $tracked = array_filter($values, fn ($value) => in_array($value['type'], static::TRACKED_VALUES, true));

        if ($tracked === []) {
            return $values;
        }

        $existing = $this->connection()->table('sentinel_values')
            ->whereIn('type', static::TRACKED_VALUES)
            ->whereIn('key_hash', array_map(fn ($value) => md5($value['key']), $tracked))
            ->get(['type', 'key', 'value'])
            ->mapWithKeys(fn ($row) => [$row->type."\0".$row->key => json_decode($row->value, true)]);

        foreach ($values as $index => $value) {
            if (! in_array($value['type'], static::TRACKED_VALUES, true) || ! is_array($value['value'])) {
                continue;
            }

            $previous = $existing->get($value['type']."\0".$value['key']);

            $values[$index]['value']['first_seen'] = $previous['first_seen'] ?? $value['timestamp'];
            $values[$index]['value']['updates'] = ($previous['updates'] ?? 0) + ($value['value']['updates'] ?? 1);

            if ($previous === null) {
                $metrics[] = [
                    'type' => 'new_'.$value['type'],
                    'key' => (string) ($value['value']['type'] ?? $value['type']),
                    'value' => null,
                    'timestamp' => $value['timestamp'],
                    'connection' => (string) ($value['value']['connection'] ?? ''),
                    'aggregates' => ['count'],
                ];
            }
        }

        return $values;
    }

    /**
     * Insert the aggregated rows or merge them into the existing ones.
     *
     * @param  string  $aggregate
     * @param  array  $rows
     * @return void
     */
    protected function upsert(string $aggregate, array $rows): void
    {
        $connection = $this->connection();
        $driver = $connection->getDriverName();

        $old = fn (string $column) => $this->wrap("sentinel_aggregates.{$column}");

        $new = fn (string $column) => match ($driver) {
            'mariadb', 'mysql' => $connection->getConfig('use_upsert_alias')
                ? $this->wrap("laragram_upsert_alias.{$column}")
                : "values({$this->wrap($column)})",
            'pgsql', 'sqlite' => '"excluded".'.$this->wrap($column),
            default => throw new RuntimeException("Unsupported database driver [{$driver}]."),
        };

        $mysql = in_array($driver, ['mariadb', 'mysql'], true);

        $value = match ($aggregate) {
            'count', 'sum' => "{$old('value')} + {$new('value')}",
            'max' => $mysql || $driver === 'pgsql' ? "greatest({$old('value')}, {$new('value')})" : "max({$old('value')}, {$new('value')})",
            'min' => $mysql || $driver === 'pgsql' ? "least({$old('value')}, {$new('value')})" : "min({$old('value')}, {$new('value')})",
            'avg' => "({$old('value')} * {$old('count')} + {$new('value')} * {$new('count')}) / ({$old('count')} + {$new('count')})",
        };

        $connection->table('sentinel_aggregates')->upsert(
            $rows,
            ['bucket', 'period', 'type', 'connection', 'aggregate', 'key_hash'],
            [
                'value' => new Expression($value),
                'count' => new Expression("{$old('count')} + {$new('count')}"),
            ]
        );
    }

    /**
     * Get the first and last bucket of a period's window.
     *
     * @param  int  $period
     * @param  int  $offset  Seconds to shift the window into the past.
     * @return array{0: int, 1: int}
     */
    protected function window(int $period, int $offset = 0): array
    {
        $now = time() - $offset;

        $last = intdiv($now, $period) * $period;

        return [$last - ($period * 59), $last];
    }

    /**
     * Get the SQL expression aggregating the stored values.
     *
     * @param  string  $aggregate
     * @return string
     */
    protected function aggregateExpression(string $aggregate): string
    {
        return match ($aggregate) {
            'count', 'sum' => 'sum('.$this->wrap('value').')',
            'max' => 'max('.$this->wrap('value').')',
            'min' => 'min('.$this->wrap('value').')',
            'avg' => 'sum('.$this->wrap('value').' * '.$this->wrap('count').') / nullif(sum('.$this->wrap('count').'), 0)',
            default => throw new RuntimeException("Unsupported aggregate [{$aggregate}]."),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function graph(array $types, string $aggregate, int $period, ?string $connection = null): Collection
    {
        [$first, $last] = $this->window($period);

        $buckets = [];

        for ($bucket = $first; $bucket <= $last; $bucket += $period) {
            $buckets[$bucket] = null;
        }

        $rows = $this->connection()->table('sentinel_aggregates')
            ->selectRaw('type, key_hash, max('.$this->wrap('key').') as '.$this->wrap('key').', bucket, '.$this->aggregateExpression($aggregate).' as value')
            ->where('period', $period)
            ->whereIn('type', $types)
            ->where('aggregate', $aggregate)
            ->where('bucket', '>=', $first)
            ->when($connection !== null, fn ($query) => $query->where('connection', $connection))
            ->groupBy('type', 'key_hash', 'bucket')
            ->get();

        return collect($types)->mapWithKeys(fn ($type) => [
            $type => $rows->where('type', $type)->groupBy('key')->map(function ($keyRows) use ($buckets) {
                foreach ($keyRows as $row) {
                    if (array_key_exists((int) $row->bucket, $buckets)) {
                        $buckets[(int) $row->bucket] = round((float) $row->value, 2);
                    }
                }

                return $buckets;
            }),
        ]);
    }

    /**
     * Get the charted buckets of the given types, summed across their keys.
     *
     * @param  array  $types
     * @param  string  $aggregate
     * @param  int  $period
     * @param  string|null  $connection
     * @return \LaraGram\Support\Collection<string, array<int, float|null>>
     */
    public function graphTotals(array $types, string $aggregate, int $period, ?string $connection = null): Collection
    {
        return $this->graph($types, $aggregate, $period, $connection)->map(function (Collection $keys) use ($period, $aggregate) {
            [$first, $last] = $this->window($period);

            $totals = [];

            for ($bucket = $first; $bucket <= $last; $bucket += $period) {
                $values = $keys->pluck($bucket)->filter(fn ($value) => $value !== null);

                $totals[$bucket] = $values->isEmpty() ? 0 : round(match ($aggregate) {
                    'max' => $values->max(),
                    'min' => $values->min(),
                    'avg' => $values->avg(),
                    default => $values->sum(),
                }, 2);
            }

            return $totals;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function aggregate(string $type, array|string $aggregates, int $period, ?string $orderBy = null, string $direction = 'desc', int $limit = 25, ?string $connection = null): Collection
    {
        $aggregates = (array) $aggregates;
        $orderBy ??= $aggregates[0];

        [$first] = $this->window($period);

        $base = fn (string $aggregate) => $this->connection()->table('sentinel_aggregates')
            ->selectRaw('key_hash, max('.$this->wrap('key').') as '.$this->wrap('key').', '.$this->aggregateExpression($aggregate).' as value')
            ->where('period', $period)
            ->where('type', $type)
            ->where('aggregate', $aggregate)
            ->where('bucket', '>=', $first)
            ->when($connection !== null, fn ($query) => $query->where('connection', $connection))
            ->groupBy('key_hash');

        $leading = $base($orderBy)
            ->orderBy('value', $direction === 'asc' ? 'asc' : 'desc')
            ->limit($limit)
            ->get();

        $results = $leading->mapWithKeys(fn ($row) => [$row->key_hash => (object) [
            'key' => $row->key,
            $orderBy => round((float) $row->value, 2),
        ]]);

        foreach (array_diff($aggregates, [$orderBy]) as $aggregate) {
            if ($results->isEmpty()) {
                break;
            }

            $base($aggregate)->whereIn('key_hash', $results->keys()->all())->get()->each(function ($row) use ($results, $aggregate) {
                $results[$row->key_hash]->{$aggregate} = round((float) $row->value, 2);
            });
        }

        return $results->values()->map(function ($row) use ($aggregates) {
            foreach ($aggregates as $aggregate) {
                $row->{$aggregate} ??= null;
            }

            return $row;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function aggregateTotal(array|string $types, string $aggregate, int $period, ?string $connection = null, int $offset = 0): Collection
    {
        $types = (array) $types;

        [$first, $last] = $this->window($period, $offset);

        $rows = $this->connection()->table('sentinel_aggregates')
            ->selectRaw('type, '.$this->aggregateExpression($aggregate).' as value')
            ->where('period', $period)
            ->whereIn('type', $types)
            ->where('aggregate', $aggregate)
            ->where('bucket', '>=', $first)
            ->where('bucket', '<=', $last)
            ->when($connection !== null, fn ($query) => $query->where('connection', $connection))
            ->groupBy('type')
            ->pluck('value', 'type');

        return collect($types)->mapWithKeys(fn ($type) => [$type => round((float) ($rows[$type] ?? 0), 2)]);
    }

    /**
     * Count the distinct keys of a type over the period.
     *
     * @param  string  $type
     * @param  int  $period
     * @param  string|null  $connection
     * @param  int  $offset
     * @return int
     */
    public function distinct(string $type, int $period, ?string $connection = null, int $offset = 0): int
    {
        [$first, $last] = $this->window($period, $offset);

        return (int) $this->connection()->table('sentinel_aggregates')
            ->where('period', $period)
            ->where('type', $type)
            ->where('aggregate', 'count')
            ->where('bucket', '>=', $first)
            ->where('bucket', '<=', $last)
            ->when($connection !== null, fn ($query) => $query->where('connection', $connection))
            ->distinct()
            ->count('key_hash');
    }

    /**
     * {@inheritdoc}
     */
    public function values(string $type, ?array $keys = null): Collection
    {
        return $this->connection()->table('sentinel_values')
            ->where('type', $type)
            ->when($keys !== null, fn ($query) => $query->whereIn('key_hash', array_map(fn ($key) => md5((string) $key), $keys)))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->key => (object) [
                'key' => $row->key,
                'timestamp' => (int) $row->timestamp,
                'value' => json_decode($row->value, true),
            ]]);
    }

    /**
     * Store a single value right away.
     *
     * @param  string  $type
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function set(string $type, string $key, mixed $value): void
    {
        $this->store([], [['type' => $type, 'key' => $key, 'value' => $value, 'timestamp' => time()]]);
    }

    /**
     * {@inheritdoc}
     */
    public function trim(): void
    {
        foreach (Sentinel::PERIODS as $period) {
            $this->connection()->table('sentinel_aggregates')
                ->where('period', $period)
                ->where('bucket', '<', time() - ($period * 60 * 2))
                ->delete();
        }

        $this->connection()->table('sentinel_values')
            ->where('timestamp', '<', time() - (max(1, $this->retentionDays) * 86400))
            ->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->connection()->table('sentinel_aggregates')->delete();
        $this->connection()->table('sentinel_values')->whereNotIn('type', ['user', 'chat'])->delete();
    }
}
