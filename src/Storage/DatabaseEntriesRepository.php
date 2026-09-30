<?php

namespace LaraGram\Sentinel\Storage;

use DateTimeInterface;
use LaraGram\Database\ConnectionResolverInterface;
use LaraGram\Sentinel\Contracts\ClearableRepository;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\PrunableRepository;
use LaraGram\Sentinel\EntryResult;
use LaraGram\Sentinel\EntryType;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Support\Collection;
use LaraGram\Support\Tempora;
use RuntimeException;

class DatabaseEntriesRepository implements EntriesRepository, ClearableRepository, PrunableRepository
{
    /**
     * The tags currently being monitored.
     *
     * @var array<int, string>|null
     */
    protected ?array $monitoredTags = null;

    /**
     * Create a new database repository.
     *
     * @param  \LaraGram\Database\ConnectionResolverInterface  $db
     * @param  string|null  $connection
     * @param  int  $chunkSize
     * @return void
     */
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
        protected int $chunkSize = 1000,
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
     * Get a query builder for a Sentinel table.
     *
     * @param  string  $table
     * @return \LaraGram\Database\Query\Builder
     */
    protected function table(string $table)
    {
        return $this->connection()->table($table);
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): EntryResult
    {
        $entry = $this->table('sentinel_entries')->where('uuid', $id)->first();

        if ($entry === null) {
            throw new RuntimeException("Sentinel entry [{$id}] was not found.");
        }

        $tags = $this->table('sentinel_entries_tags')->where('entry_uuid', $id)->pluck('tag')->all();

        return $this->toResult($entry, $tags);
    }

    /**
     * {@inheritdoc}
     */
    public function get(?string $type, EntryQueryOptions $options): Collection
    {
        $query = $this->table('sentinel_entries')
            ->when($type, fn ($query, $type) => $query->where('type', $type))
            ->when($options->batchId, fn ($query, $batchId) => $query->where('batch_id', $batchId))
            ->when($options->familyHash, fn ($query, $hash) => $query->where('family_hash', $hash))
            ->when($options->connection, fn ($query, $connection) => $query->where('connection', $connection))
            ->when($options->beforeSequence, fn ($query, $before) => $query->where('sequence', '<', $before))
            ->when($options->uuids, fn ($query, $uuids) => $query->whereIn('uuid', $uuids))
            ->when($options->tag, function ($query, $tag) {
                $tags = array_filter(array_map('trim', explode(',', $tag)));

                foreach ($tags as $tag) {
                    $query->whereIn('uuid', function ($query) use ($tag) {
                        $query->select('entry_uuid')->from('sentinel_entries_tags')->where('tag', $tag);
                    });
                }
            })
            ->when($options->search, fn ($query, $search) => $query->where('content', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->when(! $options->batchId && ! $options->familyHash && ! $options->uuids, fn ($query) => $query->where('should_display_on_index', true))
            ->orderByDesc('sequence')
            ->take($options->limit);

        $entries = $query->get();

        $tags = $entries->isEmpty() ? collect() : $this->table('sentinel_entries_tags')
            ->whereIn('entry_uuid', $entries->pluck('uuid')->all())
            ->get()
            ->groupBy('entry_uuid');

        return $entries->map(fn ($entry) => $this->toResult(
            $entry, $tags->get($entry->uuid, collect())->pluck('tag')->all()
        ))->values();
    }

    /**
     * Count the entries of each type.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->table('sentinel_entries')
            ->selectRaw('type, count(*) as aggregate')
            ->where('should_display_on_index', true)
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Turn a database row into an entry result.
     *
     * @param  object  $entry
     * @param  array  $tags
     * @return \LaraGram\Sentinel\EntryResult
     */
    protected function toResult(object $entry, array $tags = []): EntryResult
    {
        return new EntryResult(
            $entry->uuid,
            (int) $entry->sequence,
            $entry->batch_id,
            $entry->type,
            $entry->family_hash,
            $entry->connection,
            json_decode($entry->content, true) ?: [],
            $entry->created_at ? Tempora::parse($entry->created_at) : null,
            $tags,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function store(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $entries->chunk($this->chunkSize)->each(function (Collection $chunk) {
            $this->table('sentinel_entries')->insert($chunk->map(function (IncomingEntry $entry) {
                $row = $entry->toArray();

                $row['content'] = json_encode($row['content'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                return $row;
            })->values()->all());
        });

        $tags = $entries->flatMap(fn (IncomingEntry $entry) => array_map(fn ($tag) => [
            'entry_uuid' => $entry->uuid,
            'tag' => mb_substr($tag, 0, 190),
        ], $entry->tags));

        $tags->unique(fn ($row) => $row['entry_uuid'].'|'.$row['tag'])
            ->chunk($this->chunkSize)
            ->each(fn (Collection $chunk) => $this->table('sentinel_entries_tags')->insert($chunk->values()->all()));
    }

    /**
     * {@inheritdoc}
     */
    public function loadMonitoredTags(): void
    {
        try {
            $this->monitoredTags = $this->monitoring();
        } catch (\Throwable) {
            $this->monitoredTags = [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isMonitoring(array $tags): bool
    {
        if ($this->monitoredTags === null) {
            $this->loadMonitoredTags();
        }

        return count(array_intersect($tags, $this->monitoredTags)) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function monitoring(): array
    {
        return $this->table('sentinel_monitoring')->pluck('tag')->all();
    }

    /**
     * {@inheritdoc}
     */
    public function monitor(array $tags): void
    {
        $tags = array_diff($tags, $this->monitoring());

        if (empty($tags)) {
            return;
        }

        $this->table('sentinel_monitoring')->insert(array_map(fn ($tag) => ['tag' => $tag], array_values($tags)));

        $this->monitoredTags = null;
    }

    /**
     * {@inheritdoc}
     */
    public function stopMonitoring(array $tags): void
    {
        $this->table('sentinel_monitoring')->whereIn('tag', $tags)->delete();

        $this->monitoredTags = null;
    }

    /**
     * {@inheritdoc}
     */
    public function prune(DateTimeInterface $before, bool $keepExceptions = false): int
    {
        $deleted = 0;

        do {
            $uuids = $this->table('sentinel_entries')
                ->where('created_at', '<', $before)
                ->when($keepExceptions, fn ($query) => $query->where('type', '!=', EntryType::EXCEPTION))
                ->orderBy('sequence')
                ->take($this->chunkSize)
                ->pluck('uuid')
                ->all();

            if ($uuids === []) {
                break;
            }

            $this->table('sentinel_entries_tags')->whereIn('entry_uuid', $uuids)->delete();

            $deleted += $this->table('sentinel_entries')->whereIn('uuid', $uuids)->delete();
        } while (count($uuids) === $this->chunkSize);

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->table('sentinel_entries_tags')->delete();
        $this->table('sentinel_entries')->delete();
    }
}
