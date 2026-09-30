<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Telegram\ListenerMap;
use LaraGram\Support\Collection;

class MetricsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function __construct(
        protected MetricsRepository $metrics,
    ) {
    }

    /**
     * Get everything the overview shows.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function overview(Request $request)
    {
        $period = $this->period($request);
        $connection = $this->connection($request);
        $previous = $period * 60;

        $counts = ['update', 'api_call', 'api_error', 'exception', 'unhandled', 'flood_wait', 'blocked', 'new_user'];

        $now = $this->metrics->aggregateTotal($counts, 'count', $period, $connection);
        $before = $this->metrics->aggregateTotal($counts, 'count', $period, $connection, $previous);

        $avg = $this->metrics->aggregateTotal(['update', 'api_call'], 'avg', $period, $connection);
        $avgBefore = $this->metrics->aggregateTotal(['update', 'api_call'], 'avg', $period, $connection, $previous);

        return response()->json([
            'tiles' => [
                'updates' => ['value' => $now['update'], 'previous' => $before['update']],
                'users' => [
                    'value' => $this->metrics->distinct('user', $period, $connection),
                    'previous' => $this->metrics->distinct('user', $period, $connection, $previous),
                ],
                'chats' => [
                    'value' => $this->metrics->distinct('chat', $period, $connection),
                    'previous' => $this->metrics->distinct('chat', $period, $connection, $previous),
                ],
                'new_users' => ['value' => $now['new_user'], 'previous' => $before['new_user']],
                'api_calls' => ['value' => $now['api_call'], 'previous' => $before['api_call']],
                'api_errors' => ['value' => $now['api_error'], 'previous' => $before['api_error']],
                'exceptions' => ['value' => $now['exception'], 'previous' => $before['exception']],
                'unhandled' => ['value' => $now['unhandled'], 'previous' => $before['unhandled']],
                'flood_waits' => ['value' => $now['flood_wait'], 'previous' => $before['flood_wait']],
                'blocked' => ['value' => $now['blocked'], 'previous' => $before['blocked']],
                'response_time' => ['value' => $avg['update'], 'previous' => $avgBefore['update']],
                'api_time' => ['value' => $avg['api_call'], 'previous' => $avgBefore['api_call']],
            ],
            'traffic' => $this->series($this->metrics->graph(['update'], 'count', $period, $connection)['update']),
            'activity' => $this->series($this->metrics->graphTotals(['update', 'api_call', 'api_error', 'exception'], 'count', $period, $connection)),
            'latency' => $this->series($this->metrics->graphTotals(['update', 'api_call'], 'avg', $period, $connection)),
            'update_types' => $this->metrics->aggregate('update', ['count', 'avg', 'max'], $period, 'count', 'desc', 30, $connection),
            'kinds' => $this->metrics->aggregate('update_kind', 'count', $period, 'count', 'desc', 12, $connection),
            'commands' => $this->metrics->aggregate('bot_command', ['count', 'avg'], $period, 'count', 'desc', 10, $connection),
            'slow_listens' => $this->metrics->aggregate('listen', ['avg', 'max', 'count'], $period, 'avg', 'desc', 8, $connection),
            'api_methods' => $this->metrics->aggregate('api_call', ['count', 'avg', 'max'], $period, 'count', 'desc', 8, $connection),
            'api_errors' => $this->decoded($this->metrics->aggregate('api_error', 'count', $period, 'count', 'desc', 6, $connection), ['method', 'code', 'description']),
            'exceptions' => $this->decoded($this->metrics->aggregate('exception', ['count', 'max'], $period, 'count', 'desc', 6, $connection), ['class', 'location']),
            'unhandled' => $this->metrics->aggregate('unhandled', 'count', $period, 'count', 'desc', 6, $connection),
            'top_chats' => $this->withMeta($this->metrics->aggregate('chat', 'count', $period, 'count', 'desc', 6, $connection), 'chat'),
            'top_users' => $this->withMeta($this->metrics->aggregate('user', 'count', $period, 'count', 'desc', 6, $connection), 'user'),
            'webhooks' => $this->metrics->values('webhook')->values(),
        ]);
    }

    /**
     * Get the Telegram API call analytics.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function api(Request $request)
    {
        $period = $this->period($request);
        $connection = $this->connection($request);

        return response()->json([
            'totals' => $this->metrics->aggregateTotal(['api_call', 'api_error', 'flood_wait', 'blocked', 'slow_api_call'], 'count', $period, $connection),
            'avg' => $this->metrics->aggregateTotal(['api_call'], 'avg', $period, $connection)['api_call'],
            'calls' => $this->series($this->metrics->graphTotals(['api_call', 'api_error'], 'count', $period, $connection)),
            'duration' => $this->series($this->metrics->graphTotals(['api_call'], 'avg', $period, $connection)),
            'methods' => $this->metrics->aggregate('api_call', ['count', 'avg', 'max'], $period, 'count', 'desc', 50, $connection),
            'errors' => $this->decoded($this->metrics->aggregate('api_error', 'count', $period, 'count', 'desc', 30, $connection), ['method', 'code', 'description']),
            'flood' => $this->metrics->aggregate('flood_wait', ['count', 'max', 'sum'], $period, 'count', 'desc', 20, $connection),
            'slow' => $this->metrics->aggregate('slow_api_call', ['count', 'max'], $period, 'count', 'desc', 20, $connection),
        ]);
    }

    /**
     * Get the users or chats talking to the bot.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function audience(Request $request)
    {
        $period = $this->period($request);
        $connection = $this->connection($request);
        $kind = $request->query('kind') === 'chat' ? 'chat' : 'user';

        $values = $this->metrics->values($kind);

        $blocked = $this->metrics->aggregate('blocked', 'count', $period, 'count', 'desc', 1000, $connection)->pluck('count', 'key');

        $chatTypes = $kind === 'chat'
            ? $values->groupBy(fn ($value) => $value->value['type'] ?? 'unknown')->map->count()
            : collect();

        $languages = $kind === 'user'
            ? $values->groupBy(fn ($value) => $value->value['language_code'] ?? '—')->map->count()->sortDesc()->take(8)
            : collect();

        return response()->json([
            'known' => $values->count(),
            'active' => $this->metrics->distinct($kind, $period, $connection),
            'new' => $this->metrics->aggregateTotal(['new_'.$kind], 'count', $period, $connection)['new_'.$kind],
            'premium' => $kind === 'user' ? $values->filter(fn ($value) => ! empty($value->value['is_premium']))->count() : null,
            'blocked' => $blocked->count(),
            'growth' => $this->series($this->metrics->graphTotals(['new_'.$kind, $kind], 'count', $period, $connection)),
            'chat_types' => $chatTypes,
            'languages' => $languages,
            'rows' => $this->withMeta(
                $this->metrics->aggregate($kind, 'count', $period, 'count', 'desc', 100, $connection),
                $kind,
                $values
            )->map(function ($row) use ($blocked) {
                $row->blocked = (bool) $blocked->get($row->key);

                return $row;
            }),
            'recent' => $values->sortByDesc(fn ($value) => $value->value['first_seen'] ?? 0)->take(12)->values(),
        ]);
    }

    /**
     * Get the registered listens and how they perform.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Telegram\ListenerMap  $map
     * @return \LaraGram\Http\JsonResponse
     */
    public function listeners(Request $request, ListenerMap $map)
    {
        $period = $this->period($request);
        $connection = $this->connection($request);

        $stats = $this->metrics->aggregate('listen', ['count', 'avg', 'max'], $period, 'count', 'desc', 1000, $connection)->keyBy('key');

        $listens = collect($map->all())->map(function ($listen) use ($stats) {
            $stat = $stats->pull($listen['key']);

            return $listen + [
                'count' => $stat->count ?? 0,
                'avg' => $stat->avg ?? null,
                'max' => $stat->max ?? null,
            ];
        });

        return response()->json([
            'listens' => $listens->values(),
            'unregistered' => $stats->values(),
            'unhandled' => $this->metrics->aggregate('unhandled', 'count', $period, 'count', 'desc', 30, $connection),
            'commands' => $this->metrics->aggregate('bot_command', ['count', 'avg'], $period, 'count', 'desc', 50, $connection),
        ]);
    }

    /**
     * Get the conversation funnels.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function conversations(Request $request)
    {
        $period = $this->period($request);
        $connection = $this->connection($request);

        $funnels = $this->metrics->aggregate('conversation', 'count', $period, 'count', 'desc', 500, $connection)
            ->groupBy(fn ($row) => $this->decodeKey($row->key)[0] ?? 'unknown')
            ->map(function (Collection $rows, $name) {
                $steps = $rows->mapWithKeys(fn ($row) => [$this->decodeKey($row->key)[1] ?? 'unknown' => (int) $row->count]);

                $started = $steps['started'] ?? 0;
                $completed = $steps['completed'] ?? 0;

                return [
                    'name' => $name,
                    'steps' => $steps,
                    'completion' => $started > 0 ? round($completed / $started * 100, 1) : null,
                ];
            })
            ->sortByDesc(fn ($funnel) => $funnel['steps']['started'] ?? 0)
            ->values();

        return response()->json([
            'funnels' => $funnels,
            'activity' => $this->series($this->metrics->graphTotals(['conversation'], 'count', $period, $connection)),
        ]);
    }

    /**
     * Get the grouped exceptions.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function exceptions(Request $request)
    {
        $period = $this->period($request);
        $connection = $this->connection($request);

        return response()->json([
            'groups' => $this->decoded($this->metrics->aggregate('exception', ['count', 'max'], $period, 'count', 'desc', 100, $connection), ['class', 'location']),
            'graph' => $this->series($this->metrics->graphTotals(['exception'], 'count', $period, $connection)),
        ]);
    }

    /**
     * Get the application performance metrics.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function performance(Request $request)
    {
        $period = $this->period($request);

        $cache = $this->metrics->aggregate('cache', 'count', $period, 'count', 'desc', 2)->pluck('count', 'key');

        return response()->json([
            'slow_queries' => $this->decoded($this->metrics->aggregate('slow_query', ['count', 'max'], $period, 'count', 'desc', 30), ['sql', 'location']),
            'queries' => $this->metrics->aggregate('query', ['count', 'avg'], $period, 'count', 'desc', 10),
            'jobs' => $this->metrics->aggregate('job', ['count', 'avg', 'max'], $period, 'count', 'desc', 30),
            'failed_jobs' => $this->metrics->aggregate('job_failed', 'count', $period, 'count', 'desc', 30)->pluck('count', 'key'),
            'requests' => $this->metrics->aggregate('http_request', ['count', 'avg', 'max'], $period, 'max', 'desc', 30),
            'slow_updates' => $this->metrics->aggregate('slow_update', ['count', 'max'], $period, 'count', 'desc', 20, $this->connection($request)),
            'logs' => $this->metrics->aggregate('log', 'count', $period, 'count', 'desc', 10)->pluck('count', 'key'),
            'cache' => ['hit' => (int) ($cache['hit'] ?? 0), 'missed' => (int) ($cache['missed'] ?? 0)],
            'commands' => $this->metrics->aggregate('console_command', 'count', $period, 'count', 'desc', 20),
            'scheduled' => $this->metrics->aggregate('scheduled_task', 'count', $period, 'count', 'desc', 20),
            'jobs_graph' => $this->series($this->metrics->graphTotals(['job', 'job_failed'], 'count', $period)),
        ]);
    }

    /**
     * Get the servers and the network health.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function servers(Request $request)
    {
        $period = $this->period($request);

        $cpu = $this->metrics->graph(['cpu'], 'avg', $period)['cpu'];
        $memory = $this->metrics->graph(['memory'], 'avg', $period)['memory'];

        $proxy = null;

        if (app()->bound('proxy')) {
            $proxy = rescue(fn () => app('proxy')->enabled() ? app('proxy')->stats() : null, null, false);
        }

        return response()->json([
            'servers' => $this->metrics->values('system')->map(fn ($server, $key) => $server->value + [
                'key' => $key,
                'updated_at' => $server->timestamp,
                'cpu_graph' => $this->series(collect(['cpu' => $cpu->get($key, [])])),
                'memory_graph' => $this->series(collect(['memory' => $memory->get($key, [])])),
            ])->values(),
            'latency' => $this->series($this->metrics->graph(['telegram_latency'], 'avg', $period)['telegram_latency']),
            'pending' => $this->series($this->metrics->graph(['webhook_pending'], 'max', $period)['webhook_pending']),
            'proxy' => $proxy,
            'proxy_snapshot' => $this->metrics->values('proxy')->first(),
            'anti_flood' => config('bot.anti_flood'),
        ]);
    }

    /**
     * Turn a graph into chartable series.
     *
     * @param  \LaraGram\Support\Collection|array  $graph
     * @return array{buckets: array<int, int>, series: array<string, array<int, float|null>>}
     */
    protected function series(Collection|array $graph): array
    {
        $graph = collect($graph);

        $buckets = [];

        foreach ($graph as $values) {
            if (! empty($values)) {
                $buckets = array_keys((array) $values);
                break;
            }
        }

        return [
            'buckets' => $buckets,
            'series' => $graph->map(fn ($values) => array_values((array) $values))->all(),
        ];
    }

    /**
     * Decode the JSON keys of aggregated rows into named fields.
     *
     * @param  \LaraGram\Support\Collection  $rows
     * @param  array<int, string>  $fields
     * @return \LaraGram\Support\Collection
     */
    protected function decoded(Collection $rows, array $fields): Collection
    {
        return $rows->map(function ($row) use ($fields) {
            $values = $this->decodeKey($row->key);

            foreach ($fields as $index => $field) {
                $row->{$field} = $values[$index] ?? null;
            }

            return $row;
        })->values();
    }

    /**
     * Attach the stored details of users or chats to aggregated rows.
     *
     * @param  \LaraGram\Support\Collection  $rows
     * @param  string  $type
     * @param  \LaraGram\Support\Collection|null  $values
     * @return \LaraGram\Support\Collection
     */
    protected function withMeta(Collection $rows, string $type, ?Collection $values = null): Collection
    {
        $values ??= $rows->isEmpty() ? collect() : $this->metrics->values($type, $rows->pluck('key')->all());

        return $rows->map(function ($row) use ($values) {
            $row->meta = $values->get($row->key)?->value;

            return $row;
        })->values();
    }
}
