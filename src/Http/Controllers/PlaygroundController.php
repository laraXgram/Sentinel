<?php

namespace LaraGram\Sentinel\Http\Controllers;

use InvalidArgumentException;
use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\EntryType;
use LaraGram\Sentinel\Telegram\BotInspector;
use LaraGram\Sentinel\Telegram\Simulator;
use LaraGram\Sentinel\Telegram\UpdateFactory;
use RuntimeException;

class PlaygroundController extends Controller
{
    /**
     * Get what the playground form needs.
     *
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return \LaraGram\Http\JsonResponse
     */
    public function defaults(BotInspector $bots, MetricsRepository $metrics)
    {
        return response()->json([
            'enabled' => (bool) config('sentinel.playground.enabled'),
            'allow_live' => (bool) config('sentinel.playground.allow_live'),
            'connections' => array_keys($bots->connections()),
            'users' => $metrics->values('user')
                ->sortByDesc(fn ($user) => $user->value['last_seen'] ?? 0)
                ->take(12)
                ->map(fn ($user) => $user->value)
                ->values(),
        ]);
    }

    /**
     * Run a fake update through the bot.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Telegram\UpdateFactory  $factory
     * @param  \LaraGram\Sentinel\Telegram\Simulator  $simulator
     * @return \LaraGram\Http\JsonResponse
     */
    public function run(Request $request, UpdateFactory $factory, Simulator $simulator)
    {
        abort_unless(config('sentinel.playground.enabled'), 403, 'The playground is disabled.');

        try {
            $update = $factory->make($request->all());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->simulate($simulator, $update, $request->input('connection'), $request->input('mode', 'dry'));
    }

    /**
     * Run a recorded update through the bot again.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Telegram\Simulator  $simulator
     * @param  string  $id
     * @return \LaraGram\Http\JsonResponse
     */
    public function replay(Request $request, EntriesRepository $entries, Simulator $simulator, string $id)
    {
        abort_unless(config('sentinel.playground.enabled'), 403, 'The playground is disabled.');

        try {
            $entry = $entries->find($id);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        if ($entry->type !== EntryType::UPDATE || empty($entry->content['update'])) {
            return response()->json(['message' => 'Only recorded updates can be replayed.'], 422);
        }

        return $this->simulate($simulator, $entry->content['update'], $entry->connection, $request->input('mode', 'dry'));
    }

    /**
     * Run the simulation and answer with its outcome.
     *
     * @param  \LaraGram\Sentinel\Telegram\Simulator  $simulator
     * @param  array  $update
     * @param  string|null  $connection
     * @param  string  $mode
     * @return \LaraGram\Http\JsonResponse
     */
    protected function simulate(Simulator $simulator, array $update, ?string $connection, string $mode)
    {
        try {
            return response()->json(['update' => $update] + $simulator->run($update, $connection ?: null, $mode === 'live' ? 'live' : 'dry'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'update' => $update], 422);
        }
    }
}
