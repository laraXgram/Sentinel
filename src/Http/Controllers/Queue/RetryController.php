<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Sentinel\Queue\Jobs\RetryFailedJob;

class RetryController extends Controller
{
    /**
     * Retry a failed job.
     *
     * @param  string  $id
     * @return void
     */
    public function store($id)
    {
        dispatch(new RetryFailedJob($id));
    }
}
