<?php

namespace LaraGram\Sentinel;

use Closure;
use LaraGram\Contracts\Debug\ExceptionHandler;
use LaraGram\Request\Request as BotRequest;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Sentinel\Support\UpdateParser;
use LaraGram\Sentinel\Telegram\DryRunInterceptor;
use LaraGram\Support\Facades\Auth;
use LaraGram\Support\Str;
use Throwable;

class Sentinel
{
    use AuthorizesRequests,
        ListensForStorageOpportunities,
        RegistersWatchers;

    /**
     * The package version shown on the dashboard.
     */
    public const VERSION = '1.0.0';

    /**
     * The metric periods, in minutes of history, each one charted as 60 buckets.
     */
    public const PERIODS = [60, 360, 1440, 10080];

    /**
     * The callbacks that filter the entries that should be recorded.
     *
     * @var array<int, \Closure>
     */
    public static $filterUsing = [];

    /**
     * The callbacks that filter the batches that should be recorded.
     *
     * @var array<int, \Closure>
     */
    public static $filterBatchUsing = [];

    /**
     * The callbacks that add tags to the recorded entries.
     *
     * @var array<int, \Closure>
     */
    public static $tagUsing = [];

    /**
     * The callbacks executed after the entries have been stored.
     *
     * @var array<int, \Closure>
     */
    public static $afterStoringHooks = [];

    /**
     * The entries waiting to be stored.
     *
     * @var array<int, \LaraGram\Sentinel\IncomingEntry>
     */
    public static $entriesQueue = [];

    /**
     * The metrics waiting to be stored.
     *
     * @var array<int, array>
     */
    public static $metricsQueue = [];

    /**
     * The values waiting to be stored, keyed by type and key.
     *
     * @var array<string, array>
     */
    public static $valuesQueue = [];

    /**
     * Indicates if Sentinel should record entries.
     *
     * @var bool
     */
    public static $shouldRecord = false;

    /**
     * Indicates if the entries of the current batch were picked by the sampler.
     *
     * @var bool
     */
    public static $sampled = true;

    /**
     * The Telegram context of the update being handled.
     *
     * @var array{connection?: string|null, chat_id?: int|string|null, user_id?: int|string|null, update_type?: string|null}
     */
    public static $context = [];

    /**
     * Counters collected while the current update is handled.
     *
     * @var array<string, int|float>
     */
    public static $counters = [];

    /**
     * The batch ID forced for the next store, used by simulations.
     *
     * @var string|null
     */
    public static $forcedBatchId = null;

    /**
     * The running simulation, if this process was started by the playground.
     *
     * @var array{id: string, mode: string, connection: string|null}|null
     */
    public static $simulation = null;

    /**
     * Register the Sentinel watchers and start recording if necessary.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    public static function start($app)
    {
        if (! config('sentinel.enabled')) {
            return;
        }

        static::detectSimulation($app);

        static::registerWatchers($app);

        if (static::$simulation !== null
            || static::runningBotUpdate()
            || static::runningApprovedCommand($app)
            || static::handlingApprovedRequest($app)
            || static::runningWithinSurge()) {
            static::startRecording(force: static::$simulation !== null);
        }
    }

    /**
     * Determine if the process is handling a Telegram update from the webhook.
     *
     * @return bool
     */
    public static function runningBotUpdate(): bool
    {
        $payload = $_SERVER['argv'][1] ?? null;

        return is_string($payload)
            && str_starts_with(ltrim($payload), '{')
            && str_contains($payload, 'update_id');
    }

    /**
     * Determine if the application is running an approved console command.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return bool
     */
    protected static function runningApprovedCommand($app): bool
    {
        if (! $app->runningInConsole() || static::runningBotUpdate()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? null;

        if (! is_string($command) || $command === '') {
            return false;
        }

        return ! Str::is(array_merge([
            'migrate*', 'db:*', 'package:discover', 'config:*', 'optimize*', 'vendor:publish',
            'key:generate', 'list', 'help', 'about', 'sentinel:*', 'queue:restart',
        ], config('sentinel.ignore_commands', [])), $command);
    }

    /**
     * Determine if the application is handling an approved web request.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return bool
     */
    protected static function handlingApprovedRequest($app): bool
    {
        if ($app->runningInConsole() || ! $app->bound('request')) {
            return false;
        }

        $request = $app['request'];

        if (! method_exists($request, 'is')) {
            return false;
        }

        $path = trim(config('sentinel.path', 'sentinel'), '/');

        return ! $request->is(array_merge(
            [$path, $path.'/*'],
            config('sentinel.ignore_paths', [])
        ));
    }

    /**
     * Determine if the application runs inside a Surge worker.
     *
     * @return bool
     */
    protected static function runningWithinSurge(): bool
    {
        return isset($_SERVER['LARAGRAM_SURGE']) || isset($_ENV['LARAGRAM_SURGE']);
    }

    /**
     * Pick up the playground simulation this process was started for.
     *
     * @param  \LaraGram\Foundation\Application  $app
     * @return void
     */
    protected static function detectSimulation($app): void
    {
        $payload = getenv('SENTINEL_SIMULATION') ?: ($_SERVER['SENTINEL_SIMULATION'] ?? null);

        if (! $payload || ! static::runningBotUpdate()) {
            return;
        }

        $simulation = json_decode($payload, true);

        if (! is_array($simulation) || empty($simulation['id'])) {
            return;
        }

        static::$simulation = [
            'id' => (string) $simulation['id'],
            'mode' => ($simulation['mode'] ?? 'dry') === 'live' ? 'live' : 'dry',
            'connection' => $simulation['connection'] ?? null,
        ];

        static::$forcedBatchId = static::$simulation['id'];

        if (static::$simulation['mode'] === 'dry') {
            BotRequest::interceptUsing(new DryRunInterceptor);
        }

        if (static::$simulation['connection'] && $app->bound('request') && $app['request'] instanceof BotRequest) {
            rescue(fn () => $app['request']->useConnection(static::$simulation['connection']), report: false);
        }
    }

    /**
     * Start recording entries.
     *
     * @param  bool  $loadMonitoredTags
     * @param  bool  $force
     * @return void
     */
    public static function startRecording($loadMonitoredTags = true, $force = false)
    {
        if ($loadMonitoredTags) {
            rescue(fn () => app(EntriesRepository::class)->loadMonitoredTags(), report: false);
        }

        $paused = ! $force && rescue(
            fn () => (bool) cache('sentinel:pause-recording', false), false, false
        );

        static::$shouldRecord = ! $paused;
    }

    /**
     * Stop recording entries.
     *
     * @return void
     */
    public static function stopRecording()
    {
        static::$shouldRecord = false;
    }

    /**
     * Execute the given callback without recording Sentinel entries.
     *
     * @param  callable  $callback
     * @return mixed
     */
    public static function withoutRecording($callback)
    {
        $shouldRecord = static::$shouldRecord;

        static::$shouldRecord = false;

        try {
            return call_user_func($callback);
        } finally {
            static::$shouldRecord = $shouldRecord;
        }
    }

    /**
     * Determine if Sentinel is recording.
     *
     * @return bool
     */
    public static function isRecording()
    {
        return static::$shouldRecord;
    }

    /**
     * Begin the Telegram context of an incoming update.
     *
     * @param  \LaraGram\Request\Request  $request
     * @return void
     */
    public static function beginUpdate($request)
    {
        $summary = UpdateParser::summarize(rescue(fn () => $request->toArray(), [], false));

        static::$context = [
            'connection' => rescue(fn () => $request->botConnection(), null, false) ?? static::$simulation['connection'] ?? null,
            'chat_id' => $summary['chat']['id'] ?? null,
            'user_id' => $summary['user']['id'] ?? null,
            'update_type' => $summary['type'] ?? null,
        ];

        static::$counters = ['api_calls' => 0, 'api_time' => 0.0, 'api_errors' => 0, 'queries' => 0, 'query_time' => 0.0, 'exceptions' => 0];

        $rate = (float) config('sentinel.watchers.'.Watchers\UpdateWatcher::class.'.sample_rate', 1);

        static::$sampled = static::$simulation !== null || $rate >= 1 || mt_rand() / mt_getrandmax() <= $rate;
    }

    /**
     * Increment one of the counters of the current update.
     *
     * @param  string  $counter
     * @param  int|float  $by
     * @return void
     */
    public static function count(string $counter, int|float $by = 1): void
    {
        static::$counters[$counter] = (static::$counters[$counter] ?? 0) + $by;
    }

    /**
     * Get the bot connection of the current context.
     *
     * @return string|null
     */
    public static function currentConnection(): ?string
    {
        if (! empty(static::$context['connection'])) {
            return static::$context['connection'];
        }

        try {
            $request = app()->bound('request') ? app('request') : null;

            if ($request instanceof BotRequest && ($connection = $request->botConnection())) {
                return static::$context['connection'] = $connection;
            }
        } catch (Throwable) {
            //
        }

        return null;
    }

    /**
     * Record the given entry.
     *
     * @param  string  $type
     * @param  \LaraGram\Sentinel\IncomingEntry  $entry
     * @return void
     */
    protected static function record(string $type, IncomingEntry $entry)
    {
        if (! static::$shouldRecord) {
            return;
        }

        $entry->type($type);

        if ($entry->connection === null) {
            $entry->connection(static::currentConnection());
        }

        $entry->tags(static::contextTags($type));

        if (static::$simulation !== null) {
            $entry->tags(['simulated']);
        }

        if (! static::runningBotUpdate() && $type === EntryType::REQUEST) {
            try {
                if (Auth::hasResolvedGuards() && Auth::hasUser()) {
                    $entry->user(Auth::user());
                }
            } catch (Throwable) {
                //
            }
        }

        static::withoutRecording(function () use ($entry) {
            foreach (static::$tagUsing as $callback) {
                $entry->tags((array) $callback($entry));
            }

            $passes = true;

            foreach (static::$filterUsing as $callback) {
                if (! $callback($entry)) {
                    $passes = false;
                    break;
                }
            }

            if ($passes || $entry->hasMonitoredTag()) {
                static::$entriesQueue[] = $entry;
            }
        });
    }

    /**
     * Get the Telegram context tags for an entry of the given type.
     *
     * @param  string  $type
     * @return array<int, string>
     */
    protected static function contextTags(string $type): array
    {
        if (! in_array($type, [EntryType::UPDATE, EntryType::API_CALL, EntryType::CONVERSATION, EntryType::EXCEPTION, EntryType::LOG, EntryType::ALERT], true)) {
            return [];
        }

        return array_values(array_filter([
            ($connection = static::currentConnection()) ? 'connection:'.$connection : null,
            isset(static::$context['chat_id']) ? 'chat:'.static::$context['chat_id'] : null,
            isset(static::$context['user_id']) ? 'user:'.static::$context['user_id'] : null,
        ]));
    }

    /**
     * Record an incoming Telegram update.
     */
    public static function recordUpdate(IncomingEntry $entry)
    {
        static::record(EntryType::UPDATE, $entry);
    }

    /**
     * Record an outgoing Telegram API call.
     */
    public static function recordApiCall(IncomingEntry $entry)
    {
        static::record(EntryType::API_CALL, $entry);
    }

    /**
     * Record a conversation event.
     */
    public static function recordConversation(IncomingEntry $entry)
    {
        static::record(EntryType::CONVERSATION, $entry);
    }

    /**
     * Record an exception.
     */
    public static function recordException(IncomingEntry $entry)
    {
        static::record(EntryType::EXCEPTION, $entry);
    }

    /**
     * Record a log message.
     */
    public static function recordLog(IncomingEntry $entry)
    {
        static::record(EntryType::LOG, $entry);
    }

    /**
     * Record a database query.
     */
    public static function recordQuery(IncomingEntry $entry)
    {
        static::record(EntryType::QUERY, $entry);
    }

    /**
     * Record a queued job.
     */
    public static function recordJob(IncomingEntry $entry)
    {
        static::record(EntryType::JOB, $entry);
    }

    /**
     * Record a cache operation.
     */
    public static function recordCache(IncomingEntry $entry)
    {
        static::record(EntryType::CACHE, $entry);
    }

    /**
     * Record a console command.
     */
    public static function recordCommand(IncomingEntry $entry)
    {
        static::record(EntryType::COMMAND, $entry);
    }

    /**
     * Record a scheduled task.
     */
    public static function recordScheduledTask(IncomingEntry $entry)
    {
        static::record(EntryType::SCHEDULED_TASK, $entry);
    }

    /**
     * Record a web request.
     */
    public static function recordRequest(IncomingEntry $entry)
    {
        static::record(EntryType::REQUEST, $entry);
    }

    /**
     * Record an event.
     */
    public static function recordEvent(IncomingEntry $entry)
    {
        static::record(EntryType::EVENT, $entry);
    }

    /**
     * Record a sent alert.
     */
    public static function recordAlert(IncomingEntry $entry)
    {
        static::record(EntryType::ALERT, $entry);
    }

    /**
     * Record a metric.
     *
     * @param  string  $type
     * @param  string  $key
     * @param  int|float|null  $value
     * @param  array<int, string>  $aggregates  Any of: count, sum, avg, max, min.
     * @param  string|null  $connection  Use "" for metrics that do not belong to a bot.
     * @return void
     */
    public static function metric(string $type, string $key, int|float|null $value = null, array $aggregates = ['count'], ?string $connection = null): void
    {
        if (! static::$shouldRecord) {
            return;
        }

        static::$metricsQueue[] = [
            'type' => $type,
            'key' => Str::limit($key, 1000, ''),
            'value' => $value,
            'timestamp' => time(),
            'connection' => (string) ($connection ?? static::currentConnection() ?? ''),
            'aggregates' => $aggregates,
        ];
    }

    /**
     * Record a value, replacing the previous value of the same type and key.
     *
     * @param  string  $type
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public static function value(string $type, string $key, mixed $value): void
    {
        if (! static::$shouldRecord) {
            return;
        }

        static::$valuesQueue[$type."\0".$key] = [
            'type' => $type,
            'key' => $key,
            'value' => $value,
            'timestamp' => time(),
        ];
    }

    /**
     * Report the given exception to Sentinel with optional tags.
     *
     * @param  \Throwable  $e
     * @param  array  $tags
     * @return void
     */
    public static function catch($e, $tags = [])
    {
        event(new \LaraGram\Log\Events\MessageLogged('error', $e->getMessage(), [
            'exception' => $e,
            'sentinel' => $tags,
        ]));
    }

    /**
     * Set the callback that filters the entries that should be recorded.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function filter(Closure $callback)
    {
        static::$filterUsing[] = $callback;

        return new static;
    }

    /**
     * Set the callback that filters the batches that should be recorded.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function filterBatch(Closure $callback)
    {
        static::$filterBatchUsing[] = $callback;

        return new static;
    }

    /**
     * Add a callback that adds tags to the record.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function tag(Closure $callback)
    {
        static::$tagUsing[] = $callback;

        return new static;
    }

    /**
     * Add a callback that will be executed after an entry batch is stored.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function afterStoring(Closure $callback)
    {
        static::$afterStoringHooks[] = $callback;

        return new static;
    }

    /**
     * Hide the given API parameters, headers or request fields.
     *
     * @param  string  $group  One of: parameters, headers, fields.
     * @param  array<int, string>  $keys
     * @return static
     */
    public static function hide(string $group, array $keys)
    {
        config(["sentinel.hidden.{$group}" => array_values(array_unique(array_merge(
            config("sentinel.hidden.{$group}", []), $keys
        )))]);

        Redactor::flush();

        return new static;
    }

    /**
     * Store the queued entries, metrics and values and flush the queues.
     *
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public static function store(EntriesRepository $entries, MetricsRepository $metrics)
    {
        if (empty(static::$entriesQueue) && empty(static::$metricsQueue) && empty(static::$valuesQueue)) {
            return;
        }

        static::withoutRecording(function () use ($entries, $metrics) {
            $batchId = static::$forcedBatchId ?? (string) Str::orderedUuid();

            $queue = collect(static::$entriesQueue);

            if (! static::$sampled) {
                $queue = $queue->filter(fn (IncomingEntry $entry) => $entry->isException() || $entry->isFailedApiCall() || $entry->isFailedJob());
            }

            foreach (static::$filterBatchUsing as $callback) {
                if (! $callback($queue)) {
                    $queue = collect();
                    break;
                }
            }

            $queue = $queue->each(function (IncomingEntry $entry) use ($batchId) {
                $entry->batchId($batchId);

                if ($entry->isException()) {
                    $entry->withFamilyHash($entry->familyHash());
                }
            })->values();

            try {
                if ($queue->isNotEmpty()) {
                    $entries->store($queue);
                }

                if (! empty(static::$metricsQueue) || ! empty(static::$valuesQueue)) {
                    $metrics->store(static::$metricsQueue, array_values(static::$valuesQueue));

                    if (random_int(1, 500) === 1) {
                        $metrics->trim();
                    }
                }

                foreach (static::$afterStoringHooks as $callback) {
                    $callback($queue, $batchId);
                }
            } catch (Throwable $e) {
                rescue(fn () => app(ExceptionHandler::class)->report($e), report: false);
            }
        });

        static::flushEntries();
    }

    /**
     * Flush all queued entries, metrics and the current context.
     *
     * @return void
     */
    public static function flushEntries()
    {
        static::$entriesQueue = [];
        static::$metricsQueue = [];
        static::$valuesQueue = [];
        static::$context = [];
        static::$counters = [];
        static::$sampled = true;

        if (static::$simulation === null) {
            static::$forcedBatchId = null;
        }
    }
}
