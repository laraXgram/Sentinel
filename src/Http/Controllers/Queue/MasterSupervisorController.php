<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;
use LaraGram\Sentinel\Queue\ProvisioningPlan;

class MasterSupervisorController extends Controller
{
    /**
     * Get all of the master supervisors and their underlying supervisors.
     *
     * @param  \LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository  $masters
     * @param  \LaraGram\Sentinel\Queue\Contracts\SupervisorRepository  $supervisors
     * @return \LaraGram\Support\Collection
     */
    public function index(MasterSupervisorRepository $masters, SupervisorRepository $supervisors)
    {
        $masters = collect($masters->all())->keyBy('name')->sortBy('name');

        $supervisors = collect($supervisors->all())->sortBy('name')->groupBy('master');

        return $masters->each(function ($master, $name) use ($supervisors) {
            $master->supervisors = ($supervisors->get($name) ?? collect())
                ->merge(
                    collect(ProvisioningPlan::get($name)->plan[$master->environment ?? config('sentinel.queue.env') ?? config('app.env')] ?? [])
                        ->map(function ($value, $key) use ($name) {
                            return (object) [
                                'name' => $name.':'.$key,
                                'master' => $name,
                                'status' => 'inactive',
                                'processes' => [],
                                'options' => [
                                    'queue' => array_key_exists('queue', $value) && is_array($value['queue']) ? implode(',', $value['queue']) : ($value['queue'] ?? ''),
                                    'balance' => $value['balance'] ?? null,
                                ],
                            ];
                        })
                )
                ->unique('name')
                ->values();
        });
    }
}
