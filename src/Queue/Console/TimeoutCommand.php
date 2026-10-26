<?php

namespace LaraGram\Sentinel\Queue\Console;

use LaraGram\Console\Command;
use LaraGram\Sentinel\Queue\MasterSupervisor;
use LaraGram\Sentinel\Queue\ProvisioningPlan;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'sentinel:queue:timeout')]
class TimeoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:queue:timeout {environment=production : The environment name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the maximum timeout for the given environment';

    /**
     * Indicates whether the command should be shown in the Commander command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $plan = ProvisioningPlan::get(MasterSupervisor::name())->plan;

        $environment = $this->argument('environment');

        $timeout = collect($plan[$this->argument('environment')] ?? [])->max('timeout') ?? 60;

        $this->components->info('Maximum timeout for '.$environment.' environment: '.$timeout.' seconds.');
    }
}
