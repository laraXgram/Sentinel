<?php

namespace LaraGram\Sentinel;

use LaraGram\Queue\Events\JobExceptionOccurred;
use LaraGram\Queue\Events\JobFailed;
use LaraGram\Queue\Events\JobProcessed;
use LaraGram\Queue\Events\JobProcessing;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;

trait ListensForStorageOpportunities
{
    /**
     * The queued jobs being processed right now.
     *
     * @var array<int, bool>
     */
    protected static $processingJobs = [];

    /**
     * Register listeners that store the recorded entries.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    public static function listenForStorageOpportunities($app)
    {
        static::storeEntriesBeforeTermination($app);

        static::storeEntriesAfterWorkerLoop($app);

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
        });
    }

    /**
     * Store the entries after each job a queue worker processes.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function storeEntriesAfterWorkerLoop($app)
    {
        $app['events']->listen(JobProcessing::class, function ($event) {
            if ($event->connectionName !== 'sync') {
                static::startRecording();

                static::$processingJobs[] = true;
            }
        });

        $app['events']->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function ($event) use ($app) {
            if ($event->connectionName !== 'sync') {
                array_pop(static::$processingJobs);

                if (empty(static::$processingJobs)) {
                    static::storeRecordedEntries($app);
                }
            }
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
