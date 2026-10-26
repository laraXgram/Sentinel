<?php

use LaraGram\Sentinel\Http\Controllers\BotsController;
use LaraGram\Sentinel\Http\Controllers\DashboardController;
use LaraGram\Sentinel\Http\Controllers\EntriesController;
use LaraGram\Sentinel\Http\Controllers\LoginController;
use LaraGram\Sentinel\Http\Controllers\MetricsController;
use LaraGram\Sentinel\Http\Controllers\PlaygroundController;
use LaraGram\Sentinel\Http\Controllers\Queue;
use LaraGram\Sentinel\Http\Controllers\SettingsController;
use LaraGram\Support\Facades\Route;

Route::get('assets/{path}', [DashboardController::class, 'asset'])->where('path', '.*')->name('asset');

Route::get('login', [LoginController::class, 'show'])->name('login');
Route::post('login/code', [LoginController::class, 'sendCode'])->name('login.code');
Route::post('login/verify', [LoginController::class, 'verifyCode'])->name('login.verify');
Route::post('login/password', [LoginController::class, 'password'])->name('login.password');
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

Route::prefix('api')->name('api.')->group(function () {
    // Metrics...
    Route::get('overview', [MetricsController::class, 'overview'])->name('overview');
    Route::get('metrics/api', [MetricsController::class, 'api'])->name('metrics.api');
    Route::get('metrics/audience', [MetricsController::class, 'audience'])->name('metrics.audience');
    Route::get('metrics/listeners', [MetricsController::class, 'listeners'])->name('metrics.listeners');
    Route::get('metrics/conversations', [MetricsController::class, 'conversations'])->name('metrics.conversations');
    Route::get('metrics/exceptions', [MetricsController::class, 'exceptions'])->name('metrics.exceptions');
    Route::get('metrics/performance', [MetricsController::class, 'performance'])->name('metrics.performance');
    Route::get('metrics/servers', [MetricsController::class, 'servers'])->name('metrics.servers');

    // Entries...
    Route::get('entries', [EntriesController::class, 'index'])->name('entries');
    Route::get('entries/counts', [EntriesController::class, 'counts'])->name('entries.counts');
    Route::get('entries/type/{type}', [EntriesController::class, 'index'])->name('entries.type');
    Route::get('entries/{id}', [EntriesController::class, 'show'])->name('entries.show');

    // Bots & webhooks...
    Route::get('bots', [BotsController::class, 'index'])->name('bots');
    Route::get('bots/{connection}', [BotsController::class, 'show'])->name('bots.show');
    Route::post('bots/{connection}/webhook', [BotsController::class, 'setWebhook'])->name('bots.webhook.set');
    Route::delete('bots/{connection}/webhook', [BotsController::class, 'deleteWebhook'])->name('bots.webhook.delete');

    // Playground...
    Route::get('playground', [PlaygroundController::class, 'defaults'])->name('playground');
    Route::post('playground', [PlaygroundController::class, 'run'])->name('playground.run');
    Route::post('entries/{id}/replay', [PlaygroundController::class, 'replay'])->name('entries.replay');

    // Queues...
    Route::prefix('queue')->name('queue.')->group(function () {
        Route::get('stats', [Queue\DashboardStatsController::class, 'index'])->name('stats');
        Route::get('workload', [Queue\WorkloadController::class, 'index'])->name('workload');
        Route::get('masters', [Queue\MasterSupervisorController::class, 'index'])->name('masters');

        Route::get('monitoring', [Queue\MonitoringController::class, 'index'])->name('monitoring');
        Route::post('monitoring', [Queue\MonitoringController::class, 'store'])->name('monitoring.store');
        Route::get('monitoring/jobs', [Queue\MonitoringController::class, 'paginate'])->name('monitoring.jobs');
        Route::delete('monitoring/{tag}', [Queue\MonitoringController::class, 'destroy'])->where('tag', '.*')->name('monitoring.destroy');

        Route::get('metrics/jobs', [Queue\JobMetricsController::class, 'index'])->name('metrics.jobs');
        Route::get('metrics/jobs/{id}', [Queue\JobMetricsController::class, 'show'])->where('id', '.*')->name('metrics.jobs.show');
        Route::get('metrics/queues', [Queue\QueueMetricsController::class, 'index'])->name('metrics.queues');
        Route::get('metrics/queues/{id}', [Queue\QueueMetricsController::class, 'show'])->where('id', '.*')->name('metrics.queues.show');

        Route::get('batches', [Queue\BatchesController::class, 'index'])->name('batches');
        Route::get('batches/{id}', [Queue\BatchesController::class, 'show'])->name('batches.show');
        Route::post('batches/{id}/retry', [Queue\BatchesController::class, 'retry'])->name('batches.retry');

        Route::get('jobs/pending', [Queue\PendingJobsController::class, 'index'])->name('jobs.pending');
        Route::get('jobs/completed', [Queue\CompletedJobsController::class, 'index'])->name('jobs.completed');
        Route::get('jobs/silenced', [Queue\SilencedJobsController::class, 'index'])->name('jobs.silenced');
        Route::get('jobs/failed', [Queue\FailedJobsController::class, 'index'])->name('jobs.failed');
        Route::get('jobs/failed/{id}', [Queue\FailedJobsController::class, 'show'])->name('jobs.failed.show');
        Route::post('jobs/{id}/retry', [Queue\RetryController::class, 'store'])->name('jobs.retry');
        Route::get('jobs/{id}', [Queue\JobsController::class, 'show'])->name('jobs.show');
    });

    // Settings...
    Route::get('status', [SettingsController::class, 'status'])->name('status');
    Route::post('recording', [SettingsController::class, 'toggleRecording'])->name('recording');
    Route::delete('entries', [SettingsController::class, 'clear'])->name('entries.clear');
    Route::post('monitoring', [SettingsController::class, 'monitor'])->name('monitoring.store');
    Route::delete('monitoring', [SettingsController::class, 'stopMonitoring'])->name('monitoring.destroy');
});

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
