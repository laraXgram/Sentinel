<?php

namespace LaraGram\Sentinel;

use JsonSerializable;

class EntryResult implements JsonSerializable
{
    /**
     * Create a new entry result instance.
     *
     * @param  string  $id
     * @param  int|null  $sequence
     * @param  string  $batchId
     * @param  string  $type
     * @param  string|null  $familyHash
     * @param  string|null  $connection
     * @param  array  $content
     * @param  \DateTimeInterface|null  $createdAt
     * @param  array  $tags
     * @return void
     */
    public function __construct(
        public $id,
        public $sequence,
        public string $batchId,
        public string $type,
        public ?string $familyHash,
        public ?string $connection,
        public array $content,
        public $createdAt,
        public array $tags = [],
    ) {
    }

    /**
     * Get the array representation of the entry.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'batch_id' => $this->batchId,
            'type' => $this->type,
            'family_hash' => $this->familyHash,
            'connection' => $this->connection,
            'content' => $this->content,
            'tags' => $this->tags,
            'created_at' => $this->createdAt?->toIso8601String(),
            'timestamp' => $this->createdAt?->getTimestamp(),
        ];
    }
}
