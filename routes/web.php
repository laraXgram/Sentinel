<?php

use LaraGram\Sentinel\Http\Controllers\BotsController;
use LaraGram\Sentinel\Http\Controllers\DashboardController;
use LaraGram\Sentinel\Http\Controllers\EntriesController;
use LaraGram\Sentinel\Http\Controllers\MetricsController;
use LaraGram\Sentinel\Http\Controllers\PlaygroundController;
use LaraGram\Sentinel\Http\Controllers\SettingsController;
use LaraGram\Support\Facades\Route;

Route::get('assets/{path}', [DashboardController::class, 'asset'])->where('path', '.*')->name('asset');

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

    // Settings...
    Route::get('status', [SettingsController::class, 'status'])->name('status');
    Route::post('recording', [SettingsController::class, 'toggleRecording'])->name('recording');
    Route::delete('entries', [SettingsController::class, 'clear'])->name('entries.clear');
    Route::post('monitoring', [SettingsController::class, 'monitor'])->name('monitoring.store');
    Route::delete('monitoring', [SettingsController::class, 'stopMonitoring'])->name('monitoring.destroy');
});

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
