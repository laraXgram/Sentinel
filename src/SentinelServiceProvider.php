<?php

namespace LaraGram\Sentinel;

use LaraGram\Database\ConnectionResolverInterface;
use LaraGram\Sentinel\Alerts\Alerter;
use LaraGram\Sentinel\Contracts\ClearableRepository;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Contracts\PrunableRepository;
use LaraGram\Sentinel\Storage\DatabaseEntriesRepository;
use LaraGram\Sentinel\Storage\DatabaseMetricsRepository;
use LaraGram\Sentinel\Telegram\BotInspector;
use LaraGram\Support\Facades\Route;
use LaraGram\Support\ServiceProvider;

class SentinelServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sentinel.php', 'sentinel');

        $this->app->singleton(DatabaseEntriesRepository::class, fn ($app) => new DatabaseEntriesRepository(
            $app->make(ConnectionResolverInterface::class),
            config('sentinel.storage.database.connection'),
            (int) config('sentinel.storage.database.chunk', 1000),
        ));

        $this->app->singleton(DatabaseMetricsRepository::class, fn ($app) => new DatabaseMetricsRepository(
            $app->make(ConnectionResolverInterface::class),
            config('sentinel.storage.database.connection'),
            (int) config('sentinel.storage.database.chunk', 1000),
            (int) config('sentinel.retention.metrics', 7),
        ));

        $this->app->alias(DatabaseEntriesRepository::class, EntriesRepository::class);
        $this->app->alias(DatabaseEntriesRepository::class, PrunableRepository::class);
        $this->app->alias(DatabaseEntriesRepository::class, ClearableRepository::class);
        $this->app->alias(DatabaseMetricsRepository::class, MetricsRepository::class);

        $this->app->singleton(BotInspector::class);
        $this->app->singleton(Alerter::class);
        $this->app->singleton(Auth\Authenticator::class);
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerCommands();
        $this->registerPublishing();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! Sentinel::runningBotUpdate()) {
            $this->registerRoutes();

            $this->loadViewsFrom(__DIR__.'/../resources/views', 'sentinel');
        }

        if (! config('sentinel.enabled')) {
            return;
        }

        Sentinel::start($this->app);

        Sentinel::listenForStorageOpportunities($this->app);

        Sentinel::afterStoring(function ($entries, $batchId) {
            if ($entries->isNotEmpty()) {
                $this->app->make(Alerter::class)->inspect($entries, $batchId);
            }
        });
    }

    /**
     * Register the Sentinel dashboard routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        Route::group([
            'domain' => config('sentinel.domain'),
            'prefix' => trim(config('sentinel.path', 'sentinel'), '/'),
            'middleware' => config('sentinel.middleware', ['web']),
            'as' => 'sentinel.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Register the Sentinel console commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\CheckCommand::class,
            Console\ClearCommand::class,
            Console\InstallCommand::class,
            Console\PauseCommand::class,
            Console\PruneCommand::class,
            Console\ResumeCommand::class,
        ]);
    }

    /**
     * Register the package's publishable resources.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sentinel.php' => config_path('sentinel.php'),
        ], 'sentinel-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'sentinel-migrations');

        $this->publishes([
            __DIR__.'/../stubs/SentinelServiceProvider.stub' => app_path('Providers/SentinelServiceProvider.php'),
        ], 'sentinel-provider');
    }
}
