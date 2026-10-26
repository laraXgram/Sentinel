<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Queue\Contracts\JobRepository;
use LaraGram\Sentinel\Queue\Contracts\TagRepository;
use LaraGram\Sentinel\Queue\Jobs\MonitorTag;
use LaraGram\Sentinel\Queue\Jobs\StopMonitoringTag;

class MonitoringController extends Controller
{
    /**
     * The job repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\JobRepository
     */
    public $jobs;

    /**
     * The tag repository implementation.
     *
     * @var \LaraGram\Sentinel\Queue\Contracts\TagRepository
     */
    public $tags;

    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\JobRepository  $jobs
     * @param  \LaraGram\Sentinel\Queue\Contracts\TagRepository  $tags
     * @return void
     */
    public function __construct(JobRepository $jobs, TagRepository $tags)
    {
        $this->jobs = $jobs;
        $this->tags = $tags;
    }

    /**
     * Get all of the monitored tags and their job counts.
     *
     * @return \LaraGram\Support\Collection
     */
    public function index()
    {
        return collect($this->tags->monitoring())
            ->map(fn ($tag) => [
                'tag' => $tag,
                'count' => $this->tags->count($tag) + $this->tags->count('failed:'.$tag),
            ])
            ->sortBy('tag')
            ->values();
    }

    /**
     * Paginate the jobs for a given tag.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return array
     */
    public function paginate(Request $request)
    {
        $tag = $request->query('tag');

        $jobIds = $this->tags->paginate(
            $tag, $startingAt = $request->query('starting_at', 0),
            $request->query('limit', 25)
        );

        return [
            'jobs' => $this->getJobs($jobIds, $startingAt),
            'total' => $this->tags->count($tag),
        ];
    }

    /**
     * Get the jobs for the given IDs.
     *
     * @param  array  $jobIds
     * @param  int  $startingAt
     * @return \LaraGram\Support\Collection
     */
    protected function getJobs($jobIds, $startingAt = 0)
    {
        return $this->jobs
            ->getJobs($jobIds, $startingAt)
            ->map(function ($job) {
                $job->payload = json_decode($job->payload);

                return $job;
            })
            ->values();
    }

    /**
     * Start monitoring the given tag.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return void
     */
    public function store(Request $request)
    {
        dispatch(new MonitorTag($request->tag));
    }

    /**
     * Stop monitoring the given tag.
     *
     * @param  string  $tag
     * @return void
     */
    public function destroy($tag)
    {
        dispatch(new StopMonitoringTag($tag));
    }
}
