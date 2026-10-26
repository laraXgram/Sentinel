<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Queue\Contracts\JobRepository;

class PendingJobsController extends Controller
{
    /**
     * The job repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\JobRepository
     */
    public $jobs;

    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\JobRepository  $jobs
     * @return void
     */
    public function __construct(JobRepository $jobs)
    {
        $this->jobs = $jobs;
    }

    /**
     * Get all of the pending jobs.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return array
     */
    public function index(Request $request)
    {
        $jobs = $this->jobs
            ->getPending($request->query('starting_at', -1))
            ->map(function ($job) {
                $job->payload = json_decode($job->payload);

                return $job;
            })
            ->values();

        return [
            'jobs' => $jobs,
            'total' => $this->jobs->countPending(),
        ];
    }

    /**
     * Decode the given job.
     *
     * @param  object  $job
     * @return object
     */
    protected function decode($job)
    {
        $job->payload = json_decode($job->payload);

        return $job;
    }
}
