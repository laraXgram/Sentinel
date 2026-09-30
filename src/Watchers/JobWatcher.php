<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Queue\Events\JobFailed;
use LaraGram\Queue\Events\JobProcessed;
use LaraGram\Queue\Events\JobProcessing;
use LaraGram\Queue\Events\JobQueued;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\ExceptionContext;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Support\Str;

class JobWatcher extends Watcher
{
    /**
     * The start times of the jobs being processed.
     *
     * @var array<string, int|float>
     */
    protected array $started = [];

    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(JobQueued::class, [$this, 'recordQueuedJob']);
        $app['events']->listen(JobProcessing::class, [$this, 'recordProcessingJob']);
        $app['events']->listen(JobProcessed::class, [$this, 'recordProcessedJob']);
        $app['events']->listen(JobFailed::class, [$this, 'recordFailedJob']);
    }

    /**
     * Record a job being queued.
     *
     * @param  \LaraGram\Queue\Events\JobQueued  $event
     * @return void
     */
    public function recordQueuedJob(JobQueued $event)
    {
        if (! Sentinel::isRecording()) {
            return;
        }

        $name = is_object($event->job) ? get_class($event->job) : (is_string($event->job) ? $event->job : 'Closure');

        Sentinel::recordJob(IncomingEntry::make([
            'status' => 'queued',
            'name' => $name,
            'connection' => $event->connectionName,
            'queue' => $event->queue ?? 'default',
            'job_id' => $event->id,
            'delay' => $event->delay ?? null,
            'data' => is_object($event->job) ? $this->properties($event->job) : null,
        ])->tags(['job:'.class_basename($name)]));
    }

    /**
     * Remember when a job started processing.
     *
     * @param  \LaraGram\Queue\Events\JobProcessing  $event
     * @return void
     */
    public function recordProcessingJob(JobProcessing $event)
    {
        $this->started[$event->job->getJobId() ?? spl_object_hash($event->job)] = hrtime(true);
    }

    /**
     * Record a processed job.
     *
     * @param  \LaraGram\Queue\Events\JobProcessed  $event
     * @return void
     */
    public function recordProcessedJob(JobProcessed $event)
    {
        $this->recordFinishedJob($event, 'processed');
    }

    /**
     * Record a failed job.
     *
     * @param  \LaraGram\Queue\Events\JobFailed  $event
     * @return void
     */
    public function recordFailedJob(JobFailed $event)
    {
        $this->recordFinishedJob($event, 'failed', $event->exception);
    }

    /**
     * Record a job that finished processing.
     *
     * @param  object  $event
     * @param  string  $status
     * @param  \Throwable|null  $exception
     * @return void
     */
    protected function recordFinishedJob($event, string $status, $exception = null)
    {
        $id = $event->job->getJobId() ?? spl_object_hash($event->job);
        $started = $this->started[$id] ?? null;
        unset($this->started[$id]);

        if (! Sentinel::isRecording() || $event->connectionName === 'sync' && $status === 'processed') {
            return;
        }

        $name = rescue(fn () => $event->job->resolveName(), 'Unknown', false);
        $duration = $started !== null ? $this->since($started) : null;

        Sentinel::recordJob(IncomingEntry::make(array_filter([
            'status' => $status,
            'name' => $name,
            'connection' => $event->connectionName,
            'queue' => rescue(fn () => $event->job->getQueue(), null, false),
            'job_id' => $event->job->getJobId(),
            'attempts' => rescue(fn () => $event->job->attempts(), null, false),
            'duration' => $duration,
            'data' => $this->payloadData(rescue(fn () => $event->job->payload(), [], false)),
            'exception' => $exception ? [
                'class' => get_class($exception),
                'message' => Redactor::string($exception->getMessage()),
                'file' => ExceptionContext::relative($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => ExceptionContext::trace($exception, 20),
            ] : null,
        ], fn ($value) => $value !== null))->tags(array_filter([
            'job:'.class_basename($name),
            $status === 'failed' ? 'failed' : null,
        ])));

        Sentinel::metric('job', $name, $duration ?? 0, ['count', 'avg', 'max'], '');

        if ($status === 'failed') {
            Sentinel::metric('job_failed', $name, null, ['count'], '');
        }
    }

    /**
     * Extract the public properties of a job.
     *
     * @param  object  $job
     * @return array
     */
    protected function properties(object $job): array
    {
        $properties = [];

        foreach ((new \ReflectionObject($job))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (in_array($property->getName(), ['job', 'connection', 'queue', 'delay', 'middleware', 'chained', 'afterCommit'], true) || ! $property->isInitialized($job)) {
                continue;
            }

            $value = $property->getValue($job);

            $properties[$property->getName()] = is_object($value)
                ? (method_exists($value, 'getKey') ? get_class($value).':'.$value->getKey() : get_class($value))
                : $value;
        }

        return Redactor::redact($properties, 'fields');
    }

    /**
     * Get the data of a job payload.
     *
     * @param  array  $payload
     * @return array|null
     */
    protected function payloadData(array $payload): ?array
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        return ['command' => Str::limit(Redactor::string($command), 2000)];
    }
}
