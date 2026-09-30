<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;
use LaraGram\Support\ServiceProvider;
use LaraGram\Support\Str;

#[AsCommand(name: 'sentinel:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Sentinel config, migration and service provider';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->call('vendor:publish', ['--tag' => 'sentinel-config']);
        $this->call('vendor:publish', ['--tag' => 'sentinel-migrations']);

        $this->registerSentinelServiceProvider();

        $this->components->info('Sentinel installed. Run `php laragram migrate`, then open /'.trim(config('sentinel.path', 'sentinel'), '/').' in your browser.');
    }

    /**
     * Publish the SentinelServiceProvider and register it in the application.
     *
     * @return void
     */
    protected function registerSentinelServiceProvider()
    {
        $target = app_path('Providers/SentinelServiceProvider.php');

        if (file_exists($target)) {
            $this->components->warn('App\Providers\SentinelServiceProvider already exists.');

            return;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        $namespace = Str::replaceLast('\\', '', $this->getLaraGram()->getNamespace());

        file_put_contents($target, str_replace(
            '{{ namespace }}',
            $namespace,
            file_get_contents(__DIR__.'/../../stubs/SentinelServiceProvider.stub')
        ));

        if (method_exists(ServiceProvider::class, 'addProviderToBootstrapFile')) {
            ServiceProvider::addProviderToBootstrapFile($namespace.'\\Providers\\SentinelServiceProvider');
        }

        $this->components->info('Published App\Providers\SentinelServiceProvider.');
    }
}
