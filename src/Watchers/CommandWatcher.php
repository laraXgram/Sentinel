<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Console\Events\CommandFinished;
use LaraGram\Console\Events\CommandStarting;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Support\Str;

class CommandWatcher extends Watcher
{
    /**
     * The start times of the running commands.
     *
     * @var array<int, int|float>
     */
    protected array $started = [];

    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(CommandStarting::class, fn () => $this->started[] = hrtime(true));
        $app['events']->listen(CommandFinished::class, [$this, 'recordCommand']);
    }

    /**
     * Record a console command was executed.
     *
     * @param  \LaraGram\Console\Events\CommandFinished  $event
     * @return void
     */
    public function recordCommand(CommandFinished $event)
    {
        $started = array_pop($this->started);

        if (! Sentinel::isRecording() || Str::is(array_merge(['sentinel:*'], config('sentinel.ignore_commands', [])), $event->command ?? '')) {
            return;
        }

        Sentinel::recordCommand(IncomingEntry::make([
            'command' => $event->command ?? $event->input->getArguments()['command'] ?? 'default',
            'exit_code' => $event->exitCode,
            'arguments' => $event->input->getArguments(),
            'options' => array_filter($event->input->getOptions(), fn ($value) => $value !== null && $value !== false),
            'duration' => $started ? $this->since($started) : null,
        ])->tags($event->exitCode !== 0 ? ['failed'] : []));

        Sentinel::metric('console_command', (string) $event->command, null, ['count'], '');
    }
}
