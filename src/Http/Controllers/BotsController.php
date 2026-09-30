<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Telegram\BotInspector;
use LaraGram\Sentinel\Telegram\ListenerMap;

class BotsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function __construct(
        protected BotInspector $bots,
        protected MetricsRepository $metrics,
    ) {
    }

    /**
     * List the bot connections with their live webhook status.
     *
     * @return \LaraGram\Http\JsonResponse
     */
    public function index()
    {
        $cached = $this->metrics->values('bot');

        $bots = collect($this->bots->connections())->map(function ($connection, $name) use ($cached) {
            if (! $connection['configured']) {
                return $connection + ['me' => null, 'webhook' => null];
            }

            $me = $cached->get($name);

            if ($me === null || time() - $me->timestamp > 600) {
                $response = $this->bots->me($name);

                if ($response['ok'] ?? false) {
                    $this->metrics->set('bot', $name, $response['result']);
                }

                $me = (object) ['value' => $response['result'] ?? null];
            }

            $webhook = $this->bots->webhook($name);

            $this->metrics->set('webhook', $name, $webhook);

            return $connection + ['me' => $me->value, 'webhook' => $webhook];
        });

        return response()->json(['bots' => $bots->values()]);
    }

    /**
     * Inspect one bot connection.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Telegram\ListenerMap  $listeners
     * @param  string  $connection
     * @return \LaraGram\Http\JsonResponse
     */
    public function show(Request $request, ListenerMap $listeners, string $connection)
    {
        abort_unless($this->bots->has($connection), 404);

        $period = $this->period($request);
        $profile = $this->bots->profile($connection);
        $webhook = $this->bots->webhook($connection);

        $this->metrics->set('webhook', $connection, $webhook);

        if ($profile['me']) {
            $this->metrics->set('bot', $connection, $profile['me']);
        }

        $pending = $this->metrics->graph(['webhook_pending'], 'max', $period, $connection)['webhook_pending']->get($connection, []);
        $latency = $this->metrics->graph(['telegram_latency'], 'avg', $period, $connection)['telegram_latency']->get($connection, []);

        return response()->json([
            'connection' => $this->bots->connections()[$connection],
            'profile' => $profile,
            'webhook' => $webhook,
            'commands' => $listeners->compareCommands($profile['commands']),
            'history' => [
                'buckets' => array_keys($pending ?: $latency),
                'series' => [
                    'pending' => array_values($pending),
                    'latency' => array_values($latency),
                ],
            ],
        ]);
    }

    /**
     * Set the webhook of a bot connection.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $connection
     * @return \LaraGram\Http\JsonResponse
     */
    public function setWebhook(Request $request, string $connection)
    {
        abort_unless($this->bots->has($connection), 404);
        abort_unless(config('sentinel.webhook.allow_changes'), 403, 'Webhook changes are disabled.');

        $url = $request->input('url');

        if ($url !== null && ! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['ok' => false, 'description' => 'The webhook URL is not valid.'], 422);
        }

        $response = $this->bots->setWebhook($connection, [
            'url' => $url,
            'max_connections' => $request->input('max_connections'),
            'allowed_updates' => $request->input('allowed_updates'),
            'drop_pending_updates' => $request->boolean('drop_pending_updates'),
            'ip_address' => $request->input('ip_address'),
        ]);

        return response()->json($response, ($response['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Remove the webhook of a bot connection.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $connection
     * @return \LaraGram\Http\JsonResponse
     */
    public function deleteWebhook(Request $request, string $connection)
    {
        abort_unless($this->bots->has($connection), 404);
        abort_unless(config('sentinel.webhook.allow_changes'), 403, 'Webhook changes are disabled.');

        $response = $this->bots->deleteWebhook($connection, $request->boolean('drop_pending_updates'));

        return response()->json($response, ($response['ok'] ?? false) ? 200 : 422);
    }
}
