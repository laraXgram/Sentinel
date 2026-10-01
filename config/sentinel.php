<?php

use LaraGram\Sentinel\Watchers;

return [

    /*
    |--------------------------------------------------------------------------
    | Sentinel Master Switch
    |--------------------------------------------------------------------------
    |
    | This option may be used to disable all Sentinel watchers and recorders
    | regardless of their individual configuration, which simply provides a
    | single and convenient way to enable or disable Sentinel data storage.
    |
    */

    'enabled' => env('SENTINEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Sentinel Dashboard Domain & Path
    |--------------------------------------------------------------------------
    |
    | The subdomain and the URI path where the Sentinel dashboard will be
    | reachable from. Feel free to change the path to anything you like.
    |
    */

    'domain' => env('SENTINEL_DOMAIN'),

    'path' => env('SENTINEL_PATH', 'sentinel'),

    /*
    |--------------------------------------------------------------------------
    | Sentinel Dashboard Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware are assigned to every Sentinel dashboard route. The
    | Authorize middleware sends visitors to the Sentinel login screen
    | unless they are logged in (see "auth" below).
    |
    */

    'middleware' => [
        'web',
        \LaraGram\Sentinel\Http\Middleware\Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Login
    |--------------------------------------------------------------------------
    |
    | Sentinel has its own login, independent of your application's users.
    |
    | "telegram": the admins listed below type their Telegram user ID and
    | the bot sends them a one-time code. "password": a single username and
    | password from your .env file (plain text or a bcrypt/argon hash).
    |
    | Once any method is configured, the dashboard always asks to log in,
    | even locally. With nothing configured it stays open in the "local"
    | environment only, or follows the "viewSentinel" gate.
    |
    */

    'auth' => [
        'methods' => ['telegram', 'password'],

        'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('SENTINEL_ADMINS', ''))))),

        'connection' => env('SENTINEL_AUTH_CONNECTION'),

        'username' => env('SENTINEL_USERNAME', 'admin'),
        'password' => env('SENTINEL_PASSWORD'),

        'code_ttl' => 300,
        'max_attempts' => 5,
        'lifetime' => env('SENTINEL_SESSION_LIFETIME', 720),
        'notify' => env('SENTINEL_LOGIN_NOTIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sentinel Storage
    |--------------------------------------------------------------------------
    |
    | The database connection used to store recorded entries and metrics.
    | Using a dedicated connection keeps monitoring writes away from your
    | bot's own tables. Entries and metrics are flushed once per update.
    |
    */

    'storage' => [
        'database' => [
            'connection' => env('SENTINEL_DB_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | Entries older than "entries" hours and metrics older than "metrics"
    | days are removed by `php laragram sentinel:prune`. Metrics also keep
    | themselves trimmed while they are being recorded.
    |
    */

    'retention' => [
        'entries' => env('SENTINEL_ENTRIES_RETENTION', 48),
        'metrics' => env('SENTINEL_METRICS_RETENTION', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Paths & Commands
    |--------------------------------------------------------------------------
    |
    | Web requests to these paths and these console commands will not be
    | watched. The Sentinel dashboard itself is always ignored.
    |
    | Queue workers and the scheduler are listed on purpose: they are not
    | recorded as a whole (that would only collect their polling), but every
    | job and scheduled task they run is still recorded as its own batch.
    |
    */

    'ignore_paths' => [
        'favicon.ico',
        'up',
        'health',
    ],

    'ignore_commands' => [
        'sentinel:*',
        'schedule:run',
        'schedule:work',
        'queue:work',
        'queue:listen',
        'watchdog',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data
    |--------------------------------------------------------------------------
    |
    | Values of these API parameters, HTTP headers and request fields are
    | replaced with "********" before anything is stored. Bot tokens are
    | always masked, wherever they appear.
    |
    */

    'hidden' => [
        'parameters' => ['provider_token', 'secret_token', 'password', 'token'],
        'headers' => ['authorization', 'cookie', 'php-auth-pw', 'x-telegram-bot-api-secret-token', 'x-csrf-token', 'x-xsrf-token'],
        'fields' => ['password', 'password_confirmation', '_token'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Inspector
    |--------------------------------------------------------------------------
    |
    | The dashboard asks Telegram for the webhook status of every bot
    | connection. `sentinel:check` snapshots it every "interval" seconds so
    | pending updates and delivery errors can be charted over time.
    |
    */

    'webhook' => [
        'interval' => 30,
        'pending_warning' => 50,
        'pending_critical' => 500,
        'allow_changes' => env('SENTINEL_WEBHOOK_CHANGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update Playground
    |--------------------------------------------------------------------------
    |
    | The playground and the "replay" action run a fake or recorded update
    | through your bot. In "dry run" mode every Telegram API call is caught
    | and answered locally, so nothing reaches real users. Live mode really
    | sends the calls and is only allowed when "allow_live" is enabled.
    |
    */

    'playground' => [
        'enabled' => env('SENTINEL_PLAYGROUND', true),
        'allow_live' => env('SENTINEL_PLAYGROUND_LIVE', false),
        'timeout' => 30,
        'php' => env('SENTINEL_PHP_BINARY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Alerts
    |--------------------------------------------------------------------------
    |
    | Sentinel can message you on Telegram when something goes wrong: an
    | exception is thrown, the webhook reports a delivery error, pending
    | updates pile up or Telegram starts flood-limiting the bot. The same
    | alert is sent at most once per "throttle" seconds.
    |
    */

    'alerts' => [
        'enabled' => env('SENTINEL_ALERTS', false),
        'connection' => env('SENTINEL_ALERTS_CONNECTION'),
        'chat_ids' => array_filter(explode(',', (string) env('SENTINEL_ALERTS_CHAT_IDS', ''))),
        'throttle' => 600,
        'on' => [
            'exceptions' => true,
            'webhook_errors' => true,
            'pending_updates' => true,
            'flood_waits' => true,
            'failed_jobs' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | `sentinel:check` records CPU, memory and disk usage of the machine it
    | runs on. Run it on each server behind your bot with a unique name.
    |
    */

    'server_name' => env('SENTINEL_SERVER_NAME', gethostname()),

    'directories' => explode(':', env('SENTINEL_SERVER_DIRECTORIES', '/')),

    /*
    |--------------------------------------------------------------------------
    | Sentinel Watchers
    |--------------------------------------------------------------------------
    |
    | Each watcher listens to a part of your application and records it both
    | as detailed entries (browsable in the dashboard) and as time-bucketed
    | metrics (charted on the overview). "sample_rate" is the fraction of
    | entries kept; metrics are always complete.
    |
    */

    'watchers' => [
        Watchers\UpdateWatcher::class => [
            'enabled' => env('SENTINEL_UPDATE_WATCHER', true),
            'sample_rate' => env('SENTINEL_UPDATE_SAMPLE_RATE', 1),
            'slow' => 1000,
            'ignore_types' => [],
        ],

        Watchers\ApiCallWatcher::class => [
            'enabled' => env('SENTINEL_API_CALL_WATCHER', true),
            'sample_rate' => 1,
            'slow' => 1500,
            'ignore_methods' => [],
            'response_size_limit' => 64,
        ],

        Watchers\ConversationWatcher::class => env('SENTINEL_CONVERSATION_WATCHER', true),

        Watchers\ExceptionWatcher::class => env('SENTINEL_EXCEPTION_WATCHER', true),

        Watchers\LogWatcher::class => [
            'enabled' => env('SENTINEL_LOG_WATCHER', true),
            'level' => 'debug',
        ],

        Watchers\QueryWatcher::class => [
            'enabled' => env('SENTINEL_QUERY_WATCHER', true),
            'ignore_packages' => true,
            'ignore_paths' => [],
            'slow' => 100,
        ],

        Watchers\JobWatcher::class => env('SENTINEL_JOB_WATCHER', true),

        Watchers\CacheWatcher::class => [
            'enabled' => env('SENTINEL_CACHE_WATCHER', true),
            'hidden' => [],
            'ignore' => ['sentinel:*', 'antiflood:*', 'laragram:*', 'LaraGram:*', 'conversation:*'],
        ],

        Watchers\CommandWatcher::class => env('SENTINEL_COMMAND_WATCHER', true),

        Watchers\ScheduleWatcher::class => env('SENTINEL_SCHEDULE_WATCHER', true),

        Watchers\RequestWatcher::class => [
            'enabled' => env('SENTINEL_REQUEST_WATCHER', true),
            'size_limit' => env('SENTINEL_RESPONSE_SIZE_LIMIT', 64),
            'slow' => 1000,
        ],

        Watchers\EventWatcher::class => [
            'enabled' => env('SENTINEL_EVENT_WATCHER', false),
            'ignore' => [],
        ],
    ],
];
