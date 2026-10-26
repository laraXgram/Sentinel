<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Queue\Contracts\JobRepository;
use LaraGram\Sentinel\Queue\Contracts\TagRepository;

class FailedJobsController extends Controller
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
     * Get all of the failed jobs.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return array
     */
    public function index(Request $request)
    {
        $jobs = ! $request->query('tag')
            ? $this->paginate($request)
            : $this->paginateByTag($request, $request->query('tag'));

        $total = $request->query('tag')
            ? $this->tags->count('failed:'.$request->query('tag'))
            : $this->jobs->countFailed();

        return [
            'jobs' => $jobs,
            'total' => $total,
        ];
    }

    /**
     * Paginate the failed jobs for the request.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Support\Collection
     */
    protected function paginate(Request $request)
    {
        return $this->jobs
            ->getFailed($request->query('starting_at') ?: -1)
            ->map(fn ($job) => $this->decode($job));
    }

    /**
     * Paginate the failed jobs for the request and tag.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $tag
     * @return \LaraGram\Support\Collection
     */
    protected function paginateByTag(Request $request, $tag)
    {
        $jobIds = $this->tags->paginate(
            'failed:'.$tag, ($request->query('starting_at') ?: -1) + 1, 50
        );

        $startingAt = $request->query('starting_at', 0);

        return $this->jobs
            ->getJobs($jobIds, $startingAt)
            ->map(fn ($job) => $this->decode($job));
    }

    /**
     * Get a failed job instance.
     *
     * @param  string  $id
     * @return mixed
     */
    public function show($id)
    {
        return (array) $this->jobs
            ->getJobs([$id])
            ->map(fn ($job) => $this->decode($job))
            ->first();
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

        $job->exception = mb_convert_encoding($job->exception, 'UTF-8');

        $job->context = json_decode($job->context ?? '');

        $job->retried_by = collect(! is_null($job->retried_by) ? json_decode($job->retried_by) : [])
                    ->sortByDesc('retried_at')->values();

        return $job;
    }
}
