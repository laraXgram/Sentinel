<?php

namespace LaraGram\Sentinel\Queue\Jobs;

use LaraGram\Sentinel\Queue\Contracts\TagRepository;

class MonitorTag
{
    /**
     * Create a new job instance.
     *
     * @param  string  $tag  The tag to monitor.
     * @return void
     */
    public function __construct(
        public $tag,
    ) {
    }

    /**
     * Execute the job.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\TagRepository  $tags
     * @return void
     */
    public function handle(TagRepository $tags)
    {
        $tags->monitor($this->tag);
    }
}
