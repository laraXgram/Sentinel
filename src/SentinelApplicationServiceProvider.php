<?php

namespace LaraGram\Sentinel;

use LaraGram\Support\Facades\Gate;
use LaraGram\Support\ServiceProvider;

class SentinelApplicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->authorization();
    }

    /**
     * Configure the Sentinel authorization services.
     *
     * @return void
     */
    protected function authorization()
    {
        $this->gate();

        Sentinel::auth(function ($request) {
            return $this->app->environment('local') ||
                   Gate::check('viewSentinel', [$request->user()]);
        });
    }

    /**
     * Register the Sentinel gate.
     *
     * This gate determines who can access Sentinel in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewSentinel', function ($user = null) {
            return in_array($user?->email, [
                //
            ]);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
