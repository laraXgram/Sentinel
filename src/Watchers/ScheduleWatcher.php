<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Console\Events\ScheduledBackgroundTaskFinished;
use LaraGram\Console\Events\ScheduledTaskFailed;
use LaraGram\Console\Events\ScheduledTaskFinished;
use LaraGram\Console\Events\ScheduledTaskSkipped;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Redactor;

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
        $app['events']->listen(ScheduledTaskFinished::class, fn ($event) => $this->recordTask($event, $event->task->runInBackground ? 'started' : 'finished'));
        $app['events']->listen(ScheduledTaskFailed::class, fn ($event) => $this->recordTask($event, 'failed'));
        $app['events']->listen(ScheduledTaskSkipped::class, fn ($event) => $this->recordTask($event, 'skipped'));
        $app['events']->listen(ScheduledBackgroundTaskFinished::class, fn ($event) => $this->recordTask($event, 'finished'));
    }

    /**
     * Record a scheduled task.
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
        $command = $this->command($task);

        Sentinel::recordScheduledTask(IncomingEntry::make(array_filter([
            'command' => $command,
            'description' => $task->description ?? null,
            'expression' => $task->expression ?? null,
            'timezone' => is_string($task->timezone ?? null) ? $task->timezone : null,
            'status' => $status,
            'background' => ($task->runInBackground ?? false) ?: null,
            'exit_code' => $task->exitCode ?? null,
            'runtime' => isset($event->runtime) ? round($event->runtime * 1000, 2) : null,
            'exception' => isset($event->exception) ? get_class($event->exception).': '.Redactor::string($event->exception->getMessage()) : null,
        ], fn ($value) => $value !== null))->tags(array_values(array_filter([
            'task:'.$command,
            $status === 'failed' ? 'failed' : null,
            $status === 'skipped' ? 'skipped' : null,
        ]))));

        if ($status === 'skipped' || $status === 'started') {
            return;
        }

        Sentinel::metric('scheduled_task', $command, isset($event->runtime) ? $event->runtime * 1000 : null, ['count', 'avg', 'max'], '');

        if ($status === 'failed') {
            Sentinel::metric('scheduled_task_failed', $command, null, ['count'], '');
        }
    }

    /**
     * Describe the command a task runs.
     *
     * @param  object  $task
     * @return string
     */
    protected function command($task): string
    {
        if ($task->description ?? null) {
            return $task->description;
        }

        if (! $task->command) {
            return 'Closure';
        }

        // "'/usr/bin/php' 'laragram' inspire" reads better as "inspire".
        return trim(preg_replace("/^'[^']*php[^']*'\s+'?laragram'?\s*/", '', $task->command)) ?: $task->command;
    }
}
