<?php

namespace LaraGram\Sentinel\Queue;

use LaraGram\Console\Scheduling\Schedule;
use LaraGram\Contracts\Events\Dispatcher;
use LaraGram\Queue\QueueManager;
use LaraGram\Sentinel\Queue\Connectors\RedisConnector;
use LaraGram\Support\ServiceProvider;

/**
 * Sentinel, merged into Sentinel: the Redis queue supervisor, its metrics and
 * its job store. The dashboard and its API are served by Sentinel.
 */
class QueueServiceProvider extends ServiceProvider
{
    use EventMap, ServiceBindings;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->normalizeConfig();
        $this->registerEvents();
        $this->registerCommands();
        $this->registerSnapshotSchedule();
    }

    /**
     * Schedule the metrics snapshot the queue pages chart.
     *
     * @return void
     */
    protected function registerSnapshotSchedule()
    {
        if (! config('sentinel.queue.metrics.schedule', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('sentinel:queue:snapshot')->everyFiveMinutes();
        });
    }
    /**
     * Normalize the Sentinel configuration.
     *
     * @return void
     */
    protected function normalizeConfig()
    {
        if (! $this->app['config']->get('sentinel.queue.name')) {
            $this->app['config']->set('sentinel.queue.name', $this->app['config']->get('app.name'));
        }
    }

    /**
     * Register the Sentinel job events.
     *
     * @return void
     */
    protected function registerEvents()
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->events as $event => $listeners) {
            foreach ($listeners as $listener) {
                $events->listen($event, $listener);
            }
        }
    }

    /**
     * Register the Sentinel Commander commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ClearCommand::class,
                Console\ClearMetricsCommand::class,
                Console\ContinueCommand::class,
                Console\ContinueSupervisorCommand::class,
                Console\ForgetFailedCommand::class,
                Console\QueueCommand::class,
                Console\ListCommand::class,
                Console\ListenCommand::class,
                Console\PauseCommand::class,
                Console\PauseSupervisorCommand::class,
                Console\PurgeCommand::class,
                Console\SupervisorCommand::class,
                Console\SupervisorStatusCommand::class,
                Console\TerminateCommand::class,
                Console\TimeoutCommand::class,
                Console\WorkCommand::class,
            ]);

            if (method_exists($this, 'reloads')) {
                $this->reloads('sentinel:queue:terminate', 'queue');
            }
        }

        $this->commands([
            Console\SnapshotCommand::class,
            Console\StatusCommand::class,
            Console\SupervisorsCommand::class,
        ]);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(Console\WorkCommand::class, function ($app) {
            return new Console\WorkCommand($app['queue.worker'], $app['cache.store']);
        });

        $this->configure();
        $this->registerServices();
        $this->registerQueueConnectors();

        $this->registerDevCommands();
    }

    /**
     * Point the queue supervisor's store at its Redis connection.
     *
     * @return void
     */
    protected function configure()
    {
        // Bots that do not use Redis keep working; Sentinel just stays idle.
        $connection = config('sentinel.queue.use', 'default');

        if (config("database.redis.clusters.{$connection}") !== null || config("database.redis.{$connection}") !== null) {
            RedisStore::use($connection);
        }
    }

    /**
     * Run the queue supervisor instead of queue:listen under the "dev" command.
     *
     * @return void
     */
    protected function registerDevCommands()
    {
        // The supervisor only works Redis queues; other drivers keep queue:listen.
        if (class_exists(\LaraGram\Foundation\DevCommands::class) && config('queue.default') === 'redis') {
            \LaraGram\Foundation\DevCommands::commander('sentinel:queue', 'queue');
        }
    }

    /**
     * Register Sentinel's services in the container.
     *
     * @return void
     */
    protected function registerServices()
    {
        foreach ($this->serviceBindings as $key => $value) {
            is_numeric($key)
                ? $this->app->singleton($value)
                : $this->app->singleton($key, $value);
        }
    }

    /**
     * Register the custom queue connectors for Sentinel.
     *
     * @return void
     */
    protected function registerQueueConnectors()
    {
        $this->callAfterResolving(QueueManager::class, function ($manager) {
            $manager->addConnector('redis', function () {
                return new RedisConnector($this->app['redis']);
            });
        });
    }
}
