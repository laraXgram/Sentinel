<?php

namespace LaraGram\Sentinel\Console;

use LaraGram\Console\Attribute\AsCommand;
use LaraGram\Console\Command;
use LaraGram\Sentinel\Alerts\Alerter;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Telegram\BotInspector;
use LaraGram\Support\Str;
use Throwable;

#[AsCommand(name: 'sentinel:check')]
class CheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentinel:check
        {--once : Take one snapshot and exit}
        {--interval=15 : Seconds between server snapshots}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch the webhooks, the proxy pool and this server';

    /**
     * The previous CPU counters, to measure usage between snapshots.
     *
     * @var array{0: int, 1: int}|null
     */
    protected ?array $cpu = null;

    /**
     * Execute the console command.
     *
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @param  \LaraGram\Sentinel\Alerts\Alerter  $alerter
     * @return int
     */
    public function handle(MetricsRepository $metrics, BotInspector $bots, Alerter $alerter)
    {
        $interval = max(1, (int) $this->option('interval'));
        $webhookInterval = max($interval, (int) config('sentinel.webhook.interval', 30));
        $lastWebhook = 0;

        $this->components->info('Sentinel is watching. Press Ctrl+C to stop.');

        while (true) {
            $now = time();

            rescue(fn () => $this->snapshotServer($metrics));

            if ($now - $lastWebhook >= $webhookInterval || $this->option('once')) {
                $lastWebhook = $now;

                rescue(fn () => $this->snapshotWebhooks($metrics, $bots, $alerter));
                rescue(fn () => $this->snapshotProxies($metrics));
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            if (random_int(1, 100) === 1) {
                rescue(fn () => $metrics->trim());
            }

            sleep($interval);
        }
    }

    /**
     * Record the CPU, memory and disk usage of this server.
     *
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    protected function snapshotServer(MetricsRepository $metrics): void
    {
        $server = (string) config('sentinel.server_name', gethostname());
        $slug = Str::slug($server) ?: 'server';

        $memory = $this->memory();
        $cpu = $this->cpuUsage();

        $storage = collect(config('sentinel.directories', ['/']))->filter()->map(fn ($directory) => [
            'directory' => $directory,
            'total' => $total = (int) round(@disk_total_space($directory) / 1024 / 1024),
            'used' => (int) round($total - @disk_free_space($directory) / 1024 / 1024),
        ])->values()->all();

        $metrics->store([
            ['type' => 'cpu', 'key' => $slug, 'value' => $cpu, 'timestamp' => time(), 'connection' => '', 'aggregates' => ['avg', 'max']],
            ['type' => 'memory', 'key' => $slug, 'value' => $memory['used'], 'timestamp' => time(), 'connection' => '', 'aggregates' => ['avg', 'max']],
        ], [[
            'type' => 'system',
            'key' => $slug,
            'timestamp' => time(),
            'value' => [
                'name' => $server,
                'cpu' => $cpu,
                'cores' => $this->cores(),
                'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
                'memory_used' => $memory['used'],
                'memory_total' => $memory['total'],
                'storage' => $storage,
                'php' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'uptime' => $this->uptime(),
            ],
        ]]);
    }

    /**
     * Record the webhook status of every bot and alert about problems.
     *
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @param  \LaraGram\Sentinel\Alerts\Alerter  $alerter
     * @return void
     */
    protected function snapshotWebhooks(MetricsRepository $metrics, BotInspector $bots, Alerter $alerter): void
    {
        foreach ($bots->connections() as $name => $connection) {
            if (! $connection['configured']) {
                continue;
            }

            $webhook = $bots->webhook($name);
            $pending = (int) ($webhook['info']['pending_update_count'] ?? 0);

            $metrics->store([
                ['type' => 'webhook_pending', 'key' => $name, 'value' => $pending, 'timestamp' => time(), 'connection' => $name, 'aggregates' => ['max', 'avg']],
                ['type' => 'telegram_latency', 'key' => $name, 'value' => (float) ($webhook['latency'] ?? 0), 'timestamp' => time(), 'connection' => $name, 'aggregates' => ['avg', 'max']],
            ], [[
                'type' => 'webhook',
                'key' => $name,
                'timestamp' => time(),
                'value' => $webhook,
            ]]);

            $this->line(sprintf('  <fg=gray>%s</> %s <fg=gray>pending:</> %d <fg=gray>health:</> %s', now()->format('H:i:s'), $name, $pending, $webhook['health']['status'] ?? '?'));

            $this->alertAboutWebhook($alerter, $name, $webhook);
        }
    }

    /**
     * Alert about webhook problems.
     *
     * @param  \LaraGram\Sentinel\Alerts\Alerter  $alerter
     * @param  string  $connection
     * @param  array  $webhook
     * @return void
     */
    protected function alertAboutWebhook(Alerter $alerter, string $connection, array $webhook): void
    {
        $info = $webhook['info'] ?? [];

        if ($alerter->wants('webhook_errors') && ($lastError = $info['last_error_date'] ?? null) && time() - $lastError < 600) {
            $alerter->send('webhook', $connection.'|'.($info['last_error_message'] ?? ''), '🔌 Webhook error · '.e($connection), [
                'Telegram could not deliver updates to <code>'.e($info['url'] ?? '').'</code>',
                '<i>'.e($info['last_error_message'] ?? 'Unknown error').'</i>',
            ]);
        }

        if ($alerter->wants('webhook_errors') && ! ($webhook['ok'] ?? true)) {
            $alerter->send('telegram', $connection, '📡 Telegram unreachable · '.e($connection), [
                e($webhook['error'] ?? 'getWebhookInfo failed.'),
            ]);
        }

        $pending = (int) ($info['pending_update_count'] ?? 0);

        if ($alerter->wants('pending_updates') && $pending >= (int) config('sentinel.webhook.pending_critical', 500)) {
            $alerter->send('pending', $connection, '📥 Updates piling up · '.e($connection), [
                "<b>{$pending}</b> updates are waiting to be delivered.",
            ]);
        }
    }

    /**
     * Record the health of the proxy pool.
     *
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    protected function snapshotProxies(MetricsRepository $metrics): void
    {
        if (! app()->bound('proxy')) {
            return;
        }

        $proxy = app('proxy');

        if (! $proxy->enabled()) {
            return;
        }

        $pings = rescue(fn () => $proxy->pingAll(), [], false);

        $metrics->store([], [[
            'type' => 'proxy',
            'key' => 'pool',
            'timestamp' => time(),
            'value' => ['stats' => $proxy->stats(), 'pings' => $pings],
        ]]);
    }

    /**
     * Get the CPU usage percentage.
     *
     * @return int
     */
    protected function cpuUsage(): int
    {
        if (is_readable('/proc/stat') && ($line = strtok((string) file_get_contents('/proc/stat'), "\n")) && str_starts_with($line, 'cpu ')) {
            $values = array_map('intval', array_slice(preg_split('/\s+/', trim($line)), 1));
            $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
            $total = array_sum($values);

            if ($this->cpu !== null && $total > $this->cpu[1]) {
                $usage = 100 * (1 - ($idle - $this->cpu[0]) / ($total - $this->cpu[1]));
                $this->cpu = [$idle, $total];

                return (int) round(max(0, min(100, $usage)));
            }

            $this->cpu = [$idle, $total];

            usleep(250000);

            return $this->cpuUsage();
        }

        if (function_exists('sys_getloadavg') && ($load = sys_getloadavg())) {
            return (int) min(100, round($load[0] / max(1, $this->cores()) * 100));
        }

        return 0;
    }

    /**
     * Get the used and total memory in megabytes.
     *
     * @return array{used: int, total: int}
     */
    protected function memory(): array
    {
        if (is_readable('/proc/meminfo')) {
            preg_match_all('/^(\w+):\s+(\d+)/m', (string) file_get_contents('/proc/meminfo'), $matches);

            $info = array_combine($matches[1], array_map('intval', $matches[2]));
            $total = (int) round(($info['MemTotal'] ?? 0) / 1024);
            $available = (int) round(($info['MemAvailable'] ?? $info['MemFree'] ?? 0) / 1024);

            return ['used' => $total - $available, 'total' => $total];
        }

        return ['used' => (int) round(memory_get_usage(true) / 1024 / 1024), 'total' => 0];
    }

    /**
     * Get the number of CPU cores.
     *
     * @return int
     */
    protected function cores(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            return max(1, substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor'));
        }

        return 1;
    }

    /**
     * Get the uptime of the server in seconds.
     *
     * @return int|null
     */
    protected function uptime(): ?int
    {
        try {
            return is_readable('/proc/uptime') ? (int) explode(' ', (string) file_get_contents('/proc/uptime'))[0] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
