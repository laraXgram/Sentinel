<?php

namespace LaraGram\Sentinel\Http\Controllers\Queue;

use LaraGram\Sentinel\Http\Controllers\Controller;

use LaraGram\Sentinel\Queue\Contracts\JobRepository;
use LaraGram\Sentinel\Queue\Contracts\MasterSupervisorRepository;
use LaraGram\Sentinel\Queue\Contracts\MetricsRepository;
use LaraGram\Sentinel\Queue\Contracts\SupervisorRepository;
use LaraGram\Sentinel\Queue\WaitTimeCalculator;

class DashboardStatsController extends Controller
{
    /**
     * Get the key performance stats for the dashboard.
     *
     * @return array
     */
    public function index()
    {
        return [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('sentinel.queue.trim.recent_failed', config('sentinel.queue.trim.failed')),
                'recentJobs' => config('sentinel.queue.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $this->currentStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)
            ->reduce(fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0);
    }

    /**
     * Get the current status of Sentinel.
     *
     * @return string
     */
    protected function currentStatus()
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 'inactive';
        }

        return collect($masters)
            ->every(fn ($master) => $master->status === 'paused') ? 'paused' : 'running';
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)
            ->filter(fn ($master) => $master->status === 'paused')
            ->count();
    }
}
