<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Queue\Contracts\TagRepository;
use LaraGram\Sentinel\Queue\Events\JobFailed;

class StoreTagsForFailedJob
{
    /**
     * The tag repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\TagRepository
     */
    public $tags;

    /**
     * Create a new listener instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\TagRepository  $tags
     * @return void
     */
    public function __construct(TagRepository $tags)
    {
        $this->tags = $tags;
    }

    /**
     * Handle the event.
     *
     * @param  \LaraGram\Sentinel\Queue\Events\JobFailed  $event
     * @return void
     */
    public function handle(JobFailed $event)
    {
        $tags = collect($event->payload->tags())
            ->map(fn ($tag) => 'failed:'.$tag)
            ->all();

        $this->tags->addTemporary(
            config('sentinel.queue.trim.failed', 2880), $event->payload->id(), $tags
        );
    }
}
