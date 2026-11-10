<?php

namespace LaraGram\Sentinel\Docker;

use LaraGram\Support\Str;
use LaraGram\Support\Yaml\Yaml;
use RuntimeException;
use Throwable;

/**
 * The containers of an Armada project, read from the Docker daemon: their
 * state, health and resource usage, their logs, and the processes Armada
 * runs under Supervisor inside the application container.
 */
class Armada
{
    /**
     * The services that run the application, in development and in production.
     *
     * @var array<int, string>
     */
    public const APP_SERVICES = ['laragram.test', 'app'];

    /**
     * The compose project name, once resolved.
     *
     * @var string|null
     */
    protected ?string $project = null;

    /**
     * The previous CPU counters of each container, when the cache can't keep them.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    protected array $cpuSamples = [];

    /**
     * Create a new instance.
     *
     * @param  \LaraGram\Sentinel\Docker\DockerClient  $docker
     */
    public function __construct(
        protected DockerClient $docker,
    ) {
    }

    /**
     * Determine if the Armada page is enabled.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return (bool) config('sentinel.armada.enabled', true);
    }

    /**
     * Determine if containers and processes may be started, stopped and restarted.
     *
     * @return bool
     */
    public function allowsActions(): bool
    {
        return (bool) config('sentinel.armada.actions', true);
    }

    /**
     * Get the Docker client.
     *
     * @return \LaraGram\Sentinel\Docker\DockerClient
     */
    public function docker(): DockerClient
    {
        return $this->docker;
    }

    /**
     * Get the state of every service of the project.
     *
     * @param  bool  $withProcesses
     * @return array<string, mixed>
     */
    public function overview(bool $withProcesses = true): array
    {
        $overview = [
            'ok' => false,
            'error' => null,
            'host' => $this->docker->host(),
            'project' => null,
            'engine' => null,
            'services' => [],
            'declared' => $this->declaredServices(),
            'settings' => $this->settings(),
            'processes' => null,
            'processes_error' => null,
            'has_app' => false,
            'actions' => $this->allowsActions(),
        ];

        try {
            $version = $this->docker->version();
            $overview['engine'] = [
                'version' => $version['Version'] ?? null,
                'api' => $version['ApiVersion'] ?? null,
                'os' => trim(($version['Os'] ?? '').'/'.($version['Arch'] ?? ''), '/'),
            ];

            $overview['project'] = $project = $this->project();

            $containers = $this->docker->containers(["com.docker.compose.project={$project}"]);
            $services = collect($containers)->map(fn (array $container) => $this->summary($container));

            // CPU usage needs two samples: without an earlier one, take a second one shortly after...
            if ($services->contains(fn (array $service) => $service['state'] === 'running' && $service['cpu'] === null)) {
                usleep(400_000);
                $services = $services->map(fn (array $service, int $index) => $service['state'] === 'running' && $service['cpu'] === null
                    ? array_merge($service, rescue(fn () => $this->usage($containers[$index]['Id']), [], false))
                    : $service);
            }

            $overview['services'] = $services->sortBy('service')->values()->all();

            $overview['ok'] = true;
        } catch (Throwable $e) {
            $overview['error'] = $e->getMessage();

            return $overview;
        }

        $app = $this->appService($overview['services']);
        $overview['has_app'] = $app !== null;

        if ($withProcesses && $app !== null && $app['state'] === 'running') {
            try {
                $overview['processes'] = $this->processes($app['id']);
            } catch (Throwable $e) {
                $overview['processes_error'] = $e->getMessage();
            }
        }

        return $overview;
    }

    /**
     * Get the details of one service.
     *
     * @param  string  $service
     * @param  int  $lines
     * @return array<string, mixed>
     */
    public function service(string $service, int $lines = 200): array
    {
        $container = $this->container($service);
        $inspect = $this->docker->inspect($container['Id']);
        $summary = $this->summary($container, $inspect);

        if ($summary['state'] === 'running' && $summary['cpu'] === null) {
            usleep(400_000);
            $summary = array_merge($summary, rescue(fn () => $this->usage($container['Id']), [], false));
        }

        $health = collect($inspect['State']['Health']['Log'] ?? [])->take(-5)->map(fn (array $check) => [
            'start' => $check['Start'] ?? null,
            'end' => $check['End'] ?? null,
            'exit_code' => $check['ExitCode'] ?? null,
            'output' => trim((string) ($check['Output'] ?? '')),
        ])->reverse()->values()->all();

        return $summary + [
            'command' => trim(implode(' ', (array) ($inspect['Config']['Entrypoint'] ?? [])).' '.implode(' ', (array) ($inspect['Config']['Cmd'] ?? []))),
            'environment' => collect($inspect['Config']['Env'] ?? [])->map(function (string $variable) {
                [$key, $value] = array_pad(explode('=', $variable, 2), 2, '');

                return ['key' => $key, 'value' => preg_match('/PASS|SECRET|TOKEN|KEY|HASH|CREDENTIAL/i', $key) && $value !== '' ? '********' : $value];
            })->sortBy('key')->values()->all(),
            'mounts' => collect($inspect['Mounts'] ?? [])->map(fn (array $mount) => [
                'type' => $mount['Type'] ?? null,
                'source' => $mount['Name'] ?? $mount['Source'] ?? null,
                'destination' => $mount['Destination'] ?? null,
                'writable' => (bool) ($mount['RW'] ?? true),
            ])->values()->all(),
            'networks' => collect($inspect['NetworkSettings']['Networks'] ?? [])->map(fn (array $network, string $name) => [
                'name' => $name,
                'ip' => $network['IPAddress'] ?? null,
                'aliases' => array_values(array_filter((array) ($network['Aliases'] ?? []))),
            ])->values()->all(),
            'health_checks' => $health,
            'exit_code' => $inspect['State']['ExitCode'] ?? null,
            'error' => ($inspect['State']['Error'] ?? '') ?: null,
            'finished_at' => $this->time($inspect['State']['FinishedAt'] ?? null),
            'oom_killed' => (bool) ($inspect['State']['OOMKilled'] ?? false),
            'logs' => $this->docker->logs($container['Id'], $lines, (bool) ($inspect['Config']['Tty'] ?? false)),
            'processes' => in_array($summary['service'], self::APP_SERVICES, true) && $summary['state'] === 'running'
                ? rescue(fn () => $this->processes($container['Id']), null, false)
                : null,
        ];
    }

    /**
     * Start, stop or restart the container of a service.
     *
     * @param  string  $service
     * @param  string  $action
     * @return void
     */
    public function action(string $service, string $action): void
    {
        $this->docker->action($this->container($service)['Id'], $action);
    }

    /**
     * Get the processes Supervisor runs in the application container.
     *
     * @param  string  $container
     * @return array<int, array{name: string, state: string, description: string}>
     */
    public function processes(string $container): array
    {
        $result = $this->docker->exec($container, ['supervisorctl', 'status']);

        // supervisorctl exits with 3 when a process isn't running, which still lists them all...
        if (! in_array($result['exit_code'], [0, 3], true) || ! preg_match('/RUNNING|STOPPED|STARTING|FATAL|EXITED|BACKOFF/', $result['output'])) {
            throw new RuntimeException(trim($result['output']) ?: 'Supervisor did not answer.');
        }

        return collect(preg_split('/\r?\n/', trim($result['output'])))
            ->map(fn (string $line) => preg_match('/^(\S+)\s+([A-Z]+)\s*(.*)$/', trim($line), $match)
                ? ['name' => $match[1], 'state' => $match[2], 'description' => trim($match[3])]
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Start, stop or restart a process of the application container.
     *
     * @param  string  $process
     * @param  string  $action
     * @return string
     */
    public function processAction(string $process, string $action): string
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true) || ! preg_match('/^[\w.:-]+$/', $process)) {
            throw new RuntimeException('Unsupported process action.');
        }

        $app = $this->appService(collect($this->docker->containers(['com.docker.compose.project='.$this->project()]))
            ->map(fn (array $container) => $this->summary($container, null, false))
            ->all());

        if ($app === null) {
            throw new RuntimeException('The application container is not running.');
        }

        $result = $this->docker->exec($app['id'], ['supervisorctl', $action, $process]);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(trim($result['output']) ?: "Supervisor could not {$action} [{$process}].");
        }

        return trim($result['output']);
    }

    /**
     * Get the compose project name of the application.
     *
     * Inside an Armada container it is read from the container itself;
     * otherwise it is the name Compose gives the project directory.
     *
     * @return string
     */
    public function project(): string
    {
        if ($this->project !== null) {
            return $this->project;
        }

        if (filled($configured = config('sentinel.armada.project'))) {
            return $this->project = (string) $configured;
        }

        if (getenv('LARAGRAM_ARMADA') === '1' && ($hostname = gethostname())) {
            $label = rescue(fn () => $this->docker->inspect($hostname)['Config']['Labels']['com.docker.compose.project'] ?? null, null, false);

            if (filled($label)) {
                return $this->project = $label;
            }
        }

        return $this->project = preg_replace('/[^a-z0-9_-]/', '', strtolower(basename(base_path()))) ?: 'laragram';
    }

    /**
     * Get the service that runs the application.
     *
     * @param  array<int, array<string, mixed>>  $services
     * @return array<string, mixed>|null
     */
    protected function appService(array $services): ?array
    {
        return collect($services)->first(fn (array $service) => in_array($service['service'], self::APP_SERVICES, true));
    }

    /**
     * Get the container of a service of the project.
     *
     * @param  string  $service
     * @return array<string, mixed>
     */
    protected function container(string $service): array
    {
        foreach ($this->docker->containers(['com.docker.compose.project='.$this->project()]) as $container) {
            if (($container['Labels']['com.docker.compose.service'] ?? null) === $service) {
                return $container;
            }
        }

        throw new RuntimeException("The [{$service}] service has no container.");
    }

    /**
     * Summarize a container of the project.
     *
     * @param  array<string, mixed>  $container
     * @param  array<string, mixed>|null  $inspect
     * @param  bool  $withStats
     * @return array<string, mixed>
     */
    protected function summary(array $container, ?array $inspect = null, bool $withStats = true): array
    {
        $state = (string) ($container['State'] ?? 'unknown');
        $inspect ??= $withStats ? rescue(fn () => $this->docker->inspect($container['Id']), [], false) : [];

        $summary = [
            'id' => substr((string) $container['Id'], 0, 12),
            'service' => $container['Labels']['com.docker.compose.service'] ?? ltrim($container['Names'][0] ?? '', '/'),
            'container' => ltrim($container['Names'][0] ?? '', '/'),
            'image' => $container['Image'] ?? null,
            'state' => $state,
            'status' => $container['Status'] ?? null,
            'health' => $inspect['State']['Health']['Status'] ?? null,
            'created_at' => $container['Created'] ?? null,
            'started_at' => $this->time($inspect['State']['StartedAt'] ?? null),
            'restart_count' => $inspect['RestartCount'] ?? null,
            'ports' => collect($container['Ports'] ?? [])->filter(fn (array $port) => isset($port['PublicPort']))
                ->map(fn (array $port) => ['ip' => $port['IP'] ?? null, 'public' => $port['PublicPort'], 'private' => $port['PrivatePort'] ?? null, 'type' => $port['Type'] ?? 'tcp'])
                ->unique(fn (array $port) => $port['public'].'/'.$port['type'])
                ->values()->all(),
            'cpu' => null,
            'memory' => null,
            'memory_limit' => null,
            'network_rx' => null,
            'network_tx' => null,
            'block_read' => null,
            'block_write' => null,
            'pids' => null,
        ];

        if ($withStats && $state === 'running') {
            $summary = array_merge($summary, rescue(fn () => $this->usage($container['Id']), [], false));
        }

        return $summary;
    }

    /**
     * Get the resource usage of a running container.
     *
     * One-shot samples carry no previous CPU counters, so the CPU usage is
     * measured against the sample taken by the previous call.
     *
     * @param  string  $id
     * @return array<string, mixed>
     */
    protected function usage(string $id): array
    {
        $stats = $this->docker->stats($id);

        $total = (float) ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $system = (float) ($stats['cpu_stats']['system_cpu_usage'] ?? 0);
        $cpus = (int) ($stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? [1]));

        $key = 'sentinel:armada:cpu:'.substr($id, 0, 12);
        $previous = $this->cpuSamples[$key] ?? rescue(fn () => cache()->get($key), null, false);
        $this->cpuSamples[$key] = [$total, $system];
        rescue(fn () => cache()->put($key, [$total, $system], 600), null, false);

        $cpu = null;
        if (is_array($previous) && $system > $previous[1] && $total >= $previous[0]) {
            $cpu = round(($total - $previous[0]) / ($system - $previous[1]) * max(1, $cpus) * 100, 2);
        }

        $memory = $stats['memory_stats'] ?? [];
        $cache = $memory['stats']['inactive_file'] ?? $memory['stats']['total_inactive_file'] ?? $memory['stats']['cache'] ?? 0;

        $blocks = collect($stats['blkio_stats']['io_service_bytes_recursive'] ?? []);

        return [
            'cpu' => $cpu,
            'memory' => isset($memory['usage']) ? max(0, (int) $memory['usage'] - (int) $cache) : null,
            'memory_limit' => $memory['limit'] ?? null,
            'network_rx' => collect($stats['networks'] ?? [])->sum('rx_bytes'),
            'network_tx' => collect($stats['networks'] ?? [])->sum('tx_bytes'),
            'block_read' => $blocks->filter(fn ($entry) => strtolower($entry['op'] ?? '') === 'read')->sum('value'),
            'block_write' => $blocks->filter(fn ($entry) => strtolower($entry['op'] ?? '') === 'write')->sum('value'),
            'pids' => $stats['pids_stats']['current'] ?? null,
        ];
    }

    /**
     * Get the services the compose file declares.
     *
     * @return array<int, array{service: string, image: string|null}>
     */
    protected function declaredServices(): array
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $file) {
            if (is_file($path = base_path($file))) {
                $compose = rescue(fn () => Yaml::parseFile($path), [], false);

                return collect($compose['services'] ?? [])->map(fn ($service, $name) => [
                    'service' => (string) $name,
                    'image' => is_array($service) ? ($service['image'] ?? null) : null,
                ])->values()->all();
            }
        }

        return [];
    }

    /**
     * Get the Armada settings of the application.
     *
     * @return array<string, string|null>
     */
    protected function settings(): array
    {
        $environment = is_file($path = base_path('.env')) ? (string) file_get_contents($path) : '';

        $value = function (string $key) use ($environment): ?string {
            return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $environment, $match) ? trim($match[1], " \t\"'") : null;
        };

        return [
            'server' => $value('ARMADA_SERVER'),
            'updates' => $value('ARMADA_UPDATES'),
            'processes' => $value('ARMADA_PROCESSES'),
            'role' => $value('ARMADA_ROLE'),
            'api_endpoint' => $value('API_ENDPOINT'),
            'stream_files' => $value('API_STREAM_FILES'),
            'file_storage' => $value('API_FILE_STORAGE'),
        ];
    }

    /**
     * Turn a Docker time into a timestamp, null for the zero time.
     *
     * @param  string|null  $time
     * @return int|null
     */
    protected function time(?string $time): ?int
    {
        if ($time === null || str_starts_with($time, '0001-')) {
            return null;
        }

        $timestamp = strtotime(rtrim(Str::before($time, '.'), 'Z').'Z');

        return $timestamp === false ? null : $timestamp;
    }
}
