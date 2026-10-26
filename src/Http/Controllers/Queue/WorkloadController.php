<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Sentinel\Queue\Contracts\WorkloadRepository;

class WorkloadController extends Controller
{
    /**
     * Get the current queue workload for the application.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\WorkloadRepository  $workload
     * @return array
     */
    public function index(WorkloadRepository $workload)
    {
        return collect($workload->get())
            ->sortBy('name')
            ->values()
            ->toArray();
    }
}
