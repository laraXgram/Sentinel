<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Sentinel\Queue\Contracts\MetricsRepository;

class QueueMetricsController extends Controller
{
    /**
     * The metrics repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\MetricsRepository
     */
    public $metrics;

    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function __construct(MetricsRepository $metrics)
    {
        $this->metrics = $metrics;
    }

    /**
     * Get all of the measured queues.
     *
     * @return array
     */
    public function index()
    {
        return $this->metrics->measuredQueues();
    }

    /**
     * Get metrics for a given queue.
     *
     * @param  string  $id
     * @return \LaraGram\Support\Collection
     */
    public function show($id)
    {
        return collect($this->metrics->snapshotsForQueue($id))
            ->map(function ($record) {
                $record->runtime = round($record->runtime / 1000, 3);
                $record->throughput = (int) $record->throughput;

                return $record;
            });
    }
}
