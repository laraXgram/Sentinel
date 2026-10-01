<?php

namespace LaraGram\Sentinel;

use LaraGram\Console\Events\ScheduledTaskStarting;
use LaraGram\Queue\Events\JobProcessing;
use LaraGram\Queue\Events\Looping;
use LaraGram\Queue\Events\WorkerStopping;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;

trait ListensForStorageOpportunities
{
    /**
     * Indicates if a queued job or scheduled task is being recorded on its own.
     *
     * Queue workers and the scheduler run for a long time and mostly poll;
     * only the jobs and tasks they run are recorded, each as its own batch.
     *
     * @var bool
     */
    protected static $unitOpen = false;

    /**
     * Register listeners that store the recorded entries.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    public static function listenForStorageOpportunities($app)
    {
        static::storeEntriesBeforeTermination($app);

        static::storeEntriesAroundJobs($app);

        static::storeEntriesAroundScheduledTasks($app);

        static::storeEntriesAfterSurgeOperations($app);
    }

    /**
     * Store the entries once the application terminates.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function storeEntriesBeforeTermination($app)
    {
        $app->terminating(function () use ($app) {
            static::storeRecordedEntries($app);

            static::$unitOpen = false;
        });
    }

    /**
     * Record each job a queue worker processes as its own batch.
     *
     * The batch is stored when the worker loops again or stops, rather than
     * right when the job finishes, because the worker reports the exception
     * of a failed job only after the failure events.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function storeEntriesAroundJobs($app)
    {
        $app['events']->listen(JobProcessing::class, function ($event) use ($app) {
            if ($event->connectionName !== 'sync') {
                static::beginUnit($app);
            }
        });

        $app['events']->listen([Looping::class, WorkerStopping::class], function () use ($app) {
            static::endUnit($app);
        });
    }

    /**
     * Record each scheduled task as its own batch.
     *
     * A task's batch is stored when the next task starts or the scheduler
     * exits, so the exception of a failed task is part of it.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function storeEntriesAroundScheduledTasks($app)
    {
        $app['events']->listen(ScheduledTaskStarting::class, function () use ($app) {
            static::beginUnit($app);
        });
    }

    /**
     * Store the entries after each Surge request, task or tick.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function storeEntriesAfterSurgeOperations($app)
    {
        $app['events']->listen([
            'LaraGram\Surge\Events\RequestTerminated',
            'LaraGram\Surge\Events\TaskTerminated',
            'LaraGram\Surge\Events\TickTerminated',
        ], function () use ($app) {
            static::storeRecordedEntries($app);
        });
    }

    /**
     * Start recording a job or scheduled task on its own.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function beginUnit($app)
    {
        if (static::$unitOpen) {
            static::endUnit($app);
        } elseif (static::$shouldRecord) {
            // The whole process is recorded already; its entries are stored on exit.
            return;
        }

        static::startRecording();

        static::$unitOpen = static::$shouldRecord;
    }

    /**
     * Store the job or scheduled task being recorded and stop recording.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function endUnit($app)
    {
        if (! static::$unitOpen) {
            return;
        }

        static::storeRecordedEntries($app);

        static::$unitOpen = false;

        static::stopRecording();
    }

    /**
     * Store the recorded entries using the configured repositories.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function storeRecordedEntries($app)
    {
        static::store($app[EntriesRepository::class], $app[MetricsRepository::class]);
    }
}
