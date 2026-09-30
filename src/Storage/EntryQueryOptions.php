<?php

namespace LaraGram\Sentinel\Storage;

use LaraGram\Http\Request;

class EntryQueryOptions
{
    public ?string $batchId = null;
    public ?string $tag = null;
    public ?string $familyHash = null;
    public ?int $beforeSequence = null;
    public ?string $connection = null;
    public ?string $search = null;
    public ?array $uuids = null;
    public int $limit = 50;

    /**
     * Create new entry query options from the incoming request.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return static
     */
    public static function fromRequest(Request $request)
    {
        return (new static)
            ->batchId($request->query('batch_id'))
            ->tag($request->query('tag'))
            ->familyHash($request->query('family_hash'))
            ->beforeSequence($request->query('before'))
            ->connection($request->query('connection'))
            ->search($request->query('q'))
            ->limit((int) $request->query('take', 50));
    }

    /**
     * Create new entry query options for the given batch ID.
     *
     * @param  string  $batchId
     * @return static
     */
    public static function forBatchId(?string $batchId)
    {
        return (new static)->batchId($batchId)->limit(500);
    }

    public function batchId(?string $batchId)
    {
        $this->batchId = $batchId ?: null;

        return $this;
    }

    public function tag(?string $tag)
    {
        $this->tag = $tag ? trim($tag) : null;

        return $this;
    }

    public function familyHash(?string $familyHash)
    {
        $this->familyHash = $familyHash ?: null;

        return $this;
    }

    public function beforeSequence($id)
    {
        $this->beforeSequence = is_numeric($id) ? (int) $id : null;

        return $this;
    }

    public function connection(?string $connection)
    {
        $this->connection = $connection ?: null;

        return $this;
    }

    public function search(?string $search)
    {
        $this->search = $search !== null && trim($search) !== '' ? trim($search) : null;

        return $this;
    }

    public function uuids(?array $uuids)
    {
        $this->uuids = $uuids;

        return $this;
    }

    public function limit(int $limit)
    {
        $this->limit = max(1, min($limit ?: 50, 1000));

        return $this;
    }
}
