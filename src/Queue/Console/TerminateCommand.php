<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Contracts\Cache\Factory as CacheFactory;
use LaraGram\Support\Arr;
use LaraGram\Support\InteractsWithTime;
use LaraGram\Support\Str;
use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Sentinel\Queue\MasterSupervisor;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:terminate')]
class TerminateCommand extends Command
{
    use InteractsWithTime;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:terminate
                            {--wait : Wait for all workers to terminate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate the master supervisor so it can be restarted';

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Contracts\Cache\Factory  $cache
     * @param  \LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(CacheFactory $cache, MasterSupervisorRepository $masters)
    {
        if (config('sentinel.queue.fast_termination')) {
            $cache->forever(
                'sentinel:queue:terminate:wait', $this->option('wait')
            );
        }

        $masters = collect($masters->all())
            ->filter(fn ($master) => Str::startsWith($master->name, MasterSupervisor::basename()))
            ->all();

        collect(Arr::pluck($masters, 'pid'))
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to processes.'))
            ->whenEmpty(fn () => $this->components->info('No processes to terminate.'))
            ->each(function ($processId) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, &$result) {
                    return $result = posix_kill($processId, SIGTERM);
                });

                if (! $result) {
                    $this->components->error("Failed to kill process: {$processId} (".posix_strerror(posix_get_last_error()).')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));

        $this->laragram['cache']->forever('LaraGram:queue:restart', $this->currentTime());
    }
}
