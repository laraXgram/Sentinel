<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use InvalidArgumentException;
use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Process\ExecutableFinder;
use LaraGram\Console\Process\Process;

#[AsCommand(name: 'sentinel:queue:listen')]
class ListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:listen
        {--environment= : The environment name}
        {--poll : Use polling for file watching}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the queue supervisor and restart its workers on file changes';

    /**
     * The Sentinel process instance.
     *
     * @var \LaraGram\Console\Process\Process|null
     */
    protected $supervisorProcess;

    /**
     * The file watcher process instance.
     *
     * @var \LaraGram\Console\Process\Process|null
     */
    protected $watcherProcess;

    /**
     * Indicates if a termination signal has been received.
     *
     * @var int|null
     */
    protected $trappedSignal = null;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->components->info('Starting the queue supervisor and watching for file changes...');

        $this->watcherProcess = $this->startWatcher();

        if ($this->watcherProcess->isTerminated()) {
            return $this->watcherFailed();
        }

        if (! $this->startSupervisor()) {
            return Command::FAILURE;
        }

        $this->listenForChanges();

        return Command::SUCCESS;
    }

    /**
     * Start the file watcher process.
     *
     * @return \LaraGram\Console\Process\Process
     */
    protected function startWatcher()
    {
        if (empty($paths = config('sentinel.queue.watch'))) {
            throw new InvalidArgumentException(
                'List of directories / files to watch not found. Please update your "config/sentinel.php" configuration file.',
            );
        }

        $nodeExecutable = (new ExecutableFinder)->find('node');

        if (! $nodeExecutable) {
            throw new InvalidArgumentException(
                'Node could not be found. Please ensure Node is installed and available in your system PATH.',
            );
        }

        $process = new Process([
            $nodeExecutable,
            'file-watcher.cjs',
            json_encode(collect($paths)->map(fn ($path) => base_path($path))->values()->all()),
            $this->option('poll') ? '1' : '',
        ], __DIR__.'/../../bin', ['NODE_PATH' => base_path('node_modules')], null, null);

        $process->start();

        sleep(1);

        return $process;
    }

    /**
     * Start the Sentinel process.
     *
     * @return bool
     */
    protected function startSupervisor()
    {
        $command = ['php', 'laragram', 'sentinel:queue'];

        if ($environment = $this->option('environment')) {
            $command[] = '--environment='.$environment;
        }

        $this->supervisorProcess = (new Process($command))
            ->setTimeout(null);

        $this->trap([SIGINT, SIGTERM, SIGQUIT], function ($signal) {
            $this->trappedSignal = $signal;

            $this->supervisorProcess->stop(signal: $signal);
            $this->supervisorProcess->wait();

            if ($this->watcherProcess) {
                $this->watcherProcess->stop();
            }
        });

        $this->supervisorProcess->start();

        usleep(100_000);

        return ! $this->supervisorProcess->isTerminated();
    }

    /**
     * Listen for file changes and restart Sentinel when detected.
     *
     * @return void
     */
    protected function listenForChanges()
    {
        while (! $this->trappedSignal) {
            if ($this->watcherProcess->getIncrementalOutput()) {
                $this->restartSupervisor();
            }

            $this->output->write($this->supervisorProcess->getIncrementalOutput());

            if (! $this->supervisorProcess->isRunning()) {
                break;
            }

            usleep(500_000);
        }
    }

    /**
     * Restart the Sentinel process.
     *
     * @return void
     */
    protected function restartSupervisor()
    {
        $this->components->info('File changed. Restarting the queue supervisor...');

        $this->supervisorProcess->stop();
        $this->supervisorProcess->wait();

        $this->startSupervisor();
    }

    /**
     * Handle watcher process failure.
     *
     * @return int
     */
    protected function watcherFailed()
    {
        $this->components->error(
            'Unable to start file watcher. Please ensure Node.js and the chokidar npm package are installed.',
        );

        $this->output->writeln($this->watcherProcess->getErrorOutput());

        return Command::FAILURE;
    }
}
