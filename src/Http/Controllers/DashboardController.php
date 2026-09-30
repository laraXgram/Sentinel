<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Routing\Controller;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Telegram\BotInspector;

class DashboardController extends Controller
{
    /**
     * The content types of the dashboard assets.
     *
     * @var array<string, string>
     */
    protected const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];

    /**
     * Display the Sentinel dashboard.
     *
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @return \LaraGram\Http\Response
     */
    public function index(BotInspector $bots)
    {
        $path = '/'.trim(config('sentinel.path', 'sentinel'), '/');

        return response()->view('sentinel::app', [
            'version' => static::assetsVersion(),
            'base' => $path,
            'config' => [
                'path' => $path,
                'base' => rtrim(url($path), '/'),
                'version' => Sentinel::VERSION,
                'app' => config('app.name', 'LaraGram'),
                'env' => app()->environment(),
                'timezone' => config('app.timezone', 'UTC'),
                'connections' => array_values(array_map(fn ($connection) => [
                    'name' => $connection['name'],
                    'username' => $connection['username'],
                    'default' => $connection['default'],
                ], $bots->connections())),
                'default_connection' => config('bot.default'),
                'enabled' => (bool) config('sentinel.enabled'),
                'playground' => (bool) config('sentinel.playground.enabled'),
                'allow_live' => (bool) config('sentinel.playground.allow_live'),
                'webhook_changes' => (bool) config('sentinel.webhook.allow_changes'),
                'alerts' => (bool) config('sentinel.alerts.enabled'),
                'user' => Sentinel::user(request()),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Serve a dashboard asset.
     *
     * @param  string  $path
     * @return \LaraGram\Http\Response
     */
    public function asset(string $path)
    {
        $root = realpath(__DIR__.'/../../../resources/assets');
        $file = realpath($root.'/'.$path);

        if ($file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            abort(404);
        }

        $type = static::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? null;

        if ($type === null) {
            abort(404);
        }

        return response(file_get_contents($file), 200, [
            'Content-Type' => $type,
            'Cache-Control' => request()->query('v') ? 'public, max-age=31536000, immutable' : 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Get a hash that changes whenever an asset changes.
     *
     * @return string
     */
    public static function assetsVersion(): string
    {
        $root = __DIR__.'/../../../resources/assets';
        $stamp = Sentinel::VERSION;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $stamp .= $file->getPathname().$file->getMTime();
        }

        return substr(md5($stamp), 0, 10);
    }
}
