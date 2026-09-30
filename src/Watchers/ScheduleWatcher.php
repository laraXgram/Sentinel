<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Console\Events\ScheduledTaskFailed;
use LaraGram\Console\Events\ScheduledTaskFinished;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;

class ScheduleWatcher extends Watcher
{
    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(ScheduledTaskFinished::class, fn ($event) => $this->recordTask($event, 'finished'));
        $app['events']->listen(ScheduledTaskFailed::class, fn ($event) => $this->recordTask($event, 'failed'));
    }

    /**
     * Record a scheduled task that ran.
     *
     * @param  object  $event
     * @param  string  $status
     * @return void
     */
    protected function recordTask($event, string $status)
    {
        if (! Sentinel::isRecording()) {
            return;
        }

        $task = $event->task;
        $command = $task->command ?: ($task->description ?? 'Closure');

        Sentinel::recordScheduledTask(IncomingEntry::make(array_filter([
            'command' => $command,
            'description' => $task->description ?? null,
            'expression' => $task->expression ?? null,
            'timezone' => is_string($task->timezone ?? null) ? $task->timezone : null,
            'status' => $status,
            'runtime' => isset($event->runtime) ? round($event->runtime * 1000, 2) : null,
            'exception' => isset($event->exception) ? get_class($event->exception).': '.$event->exception->getMessage() : null,
        ], fn ($value) => $value !== null))->tags($status === 'failed' ? ['failed'] : []));

        Sentinel::metric('scheduled_task', (string) $command, null, ['count'], '');
    }
}
