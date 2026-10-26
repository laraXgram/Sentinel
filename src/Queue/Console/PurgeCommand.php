<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Support\Str;
use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Sentinel\Queue\Contracts\ProcessRepository;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;
use LaraGram\Sentinel\Queue\MasterSupervisor;
use LaraGram\Sentinel\Queue\ProcessInspector;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:purge')]
class PurgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:purge
                            {--signal=SIGTERM : The signal to send to the rogue processes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate any rogue queue supervisor processes';

    /**
     * @var \LaraGram\Sentinel\Queue\Contracts\SupervisorRepository
     */
    private $supervisors;

    /**
     * @var \LaraGram\Sentinel\Queue\Contracts\ProcessRepository
     */
    private $processes;

    /**
     * @var \LaraGram\Sentinel\Queue\ProcessInspector
     */
    private $inspector;

    /**
     * Create a new command instance.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\SupervisorRepository  $supervisors
     * @param  \LaraGram\Sentinel\Queue\Contracts\ProcessRepository  $processes
     * @param  \LaraGram\Sentinel\Queue\ProcessInspector  $inspector
     * @return void
     */
    public function __construct(
        SupervisorRepository $supervisors,
        ProcessRepository $processes,
        ProcessInspector $inspector,
    ) {
        parent::__construct();

        $this->supervisors = $supervisors;
        $this->processes = $processes;
        $this->inspector = $inspector;
    }

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $signal = is_numeric($signal = $this->option('signal'))
            ? $signal
            : constant($signal);

        foreach ($masters->names() as $master) {
            if (Str::startsWith($master, MasterSupervisor::basename())) {
                $this->purge($master, $signal);
            }
        }
    }

    /**
     * Purge any orphan processes.
     *
     * @param  string  $master
     * @param  int  $signal
     * @return void
     */
    public function purge($master, $signal = SIGTERM)
    {
        $this->recordOrphans($master, $signal);

        $expired = $this->processes->orphanedFor(
            $master, $this->supervisors->longestActiveTimeout()
        );

        collect($expired)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to expired processes of ['.$master.']'))
            ->each(function ($processId) use ($master, $signal) {
                $this->components->task("Process: $processId", function () use ($processId, $signal) {
                    exec("kill -s {$signal} {$processId}");
                });

                $this->processes->forgetOrphans($master, [$processId]);
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }

    /**
     * Record the orphaned Sentinel processes.
     *
     * @param  string  $master
     * @param  int  $signal
     * @return void
     */
    protected function recordOrphans($master, $signal)
    {
        $this->processes->orphaned(
            $master, $orphans = $this->inspector->orphaned()
        );

        collect($orphans)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to orphaned processes of ['.$master.']'))
            ->each(function ($processId) use ($signal) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, $signal, &$result) {
                    return $result = posix_kill($processId, $signal);
                });

                if (! $result) {
                    $this->components->error("Failed to kill orphan process: {$processId} (".posix_strerror(posix_get_last_error()).')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
