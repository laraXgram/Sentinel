<?php

namespace LaraGram\Sentinel;

use LaraGram\Support\Str;

class IncomingEntry
{
    /**
     * The entry's UUID.
     *
     * @var string
     */
    public $uuid;

    /**
     * The entry's batch ID.
     *
     * @var string
     */
    public $batchId;

    /**
     * The entry's type.
     *
     * @var string
     */
    public $type;

    /**
     * The entry's family hash.
     *
     * @var string|null
     */
    public $familyHash;

    /**
     * The bot connection the entry belongs to.
     *
     * @var string|null
     */
    public $connection;

    /**
     * The currently authenticated user, if applicable.
     *
     * @var mixed
     */
    public $user;

    /**
     * The entry's content.
     *
     * @var array
     */
    public $content = [];

    /**
     * The entry's tags.
     *
     * @var array
     */
    public $tags = [];

    /**
     * Whether the entry is listed on the index screens.
     *
     * @var bool
     */
    public $displayOnIndex = true;

    /**
     * The DateTime that indicates when the entry was recorded.
     *
     * @var \DateTimeInterface
     */
    public $recordedAt;

    /**
     * Create a new incoming entry instance.
     *
     * @param  array  $content
     * @param  string|null  $uuid
     * @return void
     */
    public function __construct(array $content, $uuid = null)
    {
        $this->uuid = $uuid ?: (string) Str::orderedUuid();

        $this->recordedAt = now();

        $this->content = array_merge($content, ['hostname' => gethostname()]);
    }

    /**
     * Create a new entry instance.
     *
     * @param  mixed  ...$arguments
     * @return static
     */
    public static function make(...$arguments)
    {
        return new static(...$arguments);
    }

    /**
     * Assign the entry a given batch ID.
     *
     * @param  string  $batchId
     * @return $this
     */
    public function batchId(string $batchId)
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Assign the entry a given type.
     *
     * @param  string  $type
     * @return $this
     */
    public function type(string $type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Assign the entry a family hash.
     *
     * @param  string|null  $familyHash
     * @return $this
     */
    public function withFamilyHash($familyHash)
    {
        $this->familyHash = $familyHash;

        return $this;
    }

    /**
     * Assign the entry the bot connection it belongs to.
     *
     * @param  string|null  $connection
     * @return $this
     */
    public function connection($connection)
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set the currently authenticated user.
     *
     * @param  mixed  $user
     * @return $this
     */
    public function user($user)
    {
        $this->user = $user;

        $this->content = array_merge($this->content, [
            'user' => [
                'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : ($user->id ?? null),
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ],
        ]);

        return $this;
    }

    /**
     * Merge tags into the entry's existing tags.
     *
     * @param  array  $tags
     * @return $this
     */
    public function tags(array $tags)
    {
        $this->tags = array_values(array_unique(array_filter(array_merge($this->tags, $tags), function ($tag) {
            return is_string($tag) && $tag !== '';
        })));

        return $this;
    }

    /**
     * Determine if the incoming entry has a monitored tag.
     *
     * @return bool
     */
    public function hasMonitoredTag()
    {
        if (! empty($this->tags)) {
            return app(Contracts\EntriesRepository::class)->isMonitoring($this->tags);
        }

        return false;
    }

    /**
     * Hide the entry from the index screens.
     *
     * @return $this
     */
    public function hideFromIndex()
    {
        $this->displayOnIndex = false;

        return $this;
    }

    /**
     * Determine if the incoming entry is an exception.
     *
     * @return bool
     */
    public function isException()
    {
        return false;
    }

    /**
     * Determine if the incoming entry is a failed Telegram API call.
     *
     * @return bool
     */
    public function isFailedApiCall()
    {
        return $this->type === EntryType::API_CALL && ($this->content['ok'] ?? true) === false;
    }

    /**
     * Determine if the incoming entry is a failed job.
     *
     * @return bool
     */
    public function isFailedJob()
    {
        return $this->type === EntryType::JOB && ($this->content['status'] ?? null) === 'failed';
    }

    /**
     * Get the family look-up hash for the incoming entry.
     *
     * @return string|null
     */
    public function familyHash()
    {
        return $this->familyHash;
    }

    /**
     * Get an array representation of the entry for storage.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'uuid' => $this->uuid,
            'batch_id' => $this->batchId,
            'family_hash' => $this->familyHash,
            'type' => $this->type,
            'connection' => $this->connection,
            'should_display_on_index' => $this->displayOnIndex,
            'content' => $this->content,
            'created_at' => $this->recordedAt->toDateTimeString(),
        ];
    }
}
