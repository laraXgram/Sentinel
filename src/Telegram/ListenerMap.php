<?php

namespace LaraGram\Sentinel\Telegram;

use LaraGram\Sentinel\Watchers\UpdateWatcher;
use LaraGram\Support\Str;

class ListenerMap
{
    /**
     * Get every registered bot listen.
     *
     * @return array<int, array{key: string, verbs: array, pattern: string, name: string|null, action: string, middleware: array, command: string|null}>
     */
    public function all(): array
    {
        $listener = rescue(fn () => app('listener'), null, false);

        if ($listener === null) {
            return [];
        }

        $listens = rescue(fn () => $listener->getListens()->getListens(), [], false);

        $rows = [];

        foreach ($listens as $listen) {
            $verbs = rescue(fn () => array_values((array) $listen->methods()), [], false);
            $pattern = rescue(fn () => (string) $listen->pattern(), '', false);

            $rows[] = [
                'key' => UpdateWatcher::listenKey($listen),
                'verbs' => $verbs,
                'pattern' => $pattern,
                'name' => rescue(fn () => $listen->getName(), null, false),
                'action' => UpdateWatcher::listenAction($listen),
                'middleware' => rescue(fn () => array_values(array_filter((array) $listen->middleware(), 'is_string')), [], false),
                'connections' => rescue(fn () => array_values((array) ($listen->getAction('connections') ?? [])), [], false),
                'command' => in_array('COMMAND', $verbs, true) ? $this->command($pattern) : null,
            ];
        }

        return $rows;
    }

    /**
     * Compare the commands registered with Telegram to the command listens.
     *
     * @param  array<string, array<int, array{command: string, description: string}>>  $registered
     * @return array{unhandled: array<int, string>, unregistered: array<int, string>, matched: array<int, string>}
     */
    public function compareCommands(array $registered): array
    {
        $telegram = collect($registered)->flatten(1)->pluck('command')->map(fn ($command) => strtolower((string) $command))->unique()->values();

        $listens = collect($this->all())->pluck('command')->filter()->map(fn ($command) => strtolower($command))->unique()->values();

        $dynamic = $listens->contains(fn ($command) => Str::contains($command, ['{', '*', '(']));

        return [
            'unhandled' => $dynamic ? [] : $telegram->diff($listens)->values()->all(),
            'unregistered' => $listens->reject(fn ($command) => Str::contains($command, ['{', '*', '(']) || $command === 'start')->diff($telegram)->values()->all(),
            'matched' => $telegram->intersect($listens)->values()->all(),
        ];
    }

    /**
     * Get the command a COMMAND pattern listens to.
     *
     * @param  string  $pattern
     * @return string
     */
    protected function command(string $pattern): string
    {
        return ltrim(explode(' ', trim($pattern))[0], '/');
    }
}
