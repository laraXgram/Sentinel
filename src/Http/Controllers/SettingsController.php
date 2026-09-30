<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Sentinel;

class SettingsController extends Controller
{
    /**
     * Get the recording status of Sentinel.
     *
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @return \LaraGram\Http\JsonResponse
     */
    public function status(EntriesRepository $entries)
    {
        return response()->json([
            'enabled' => (bool) config('sentinel.enabled'),
            'recording' => ! cache('sentinel:pause-recording', false),
            'version' => Sentinel::VERSION,
            'counts' => method_exists($entries, 'counts') ? $entries->counts() : [],
            'monitoring' => $entries->monitoring(),
            'watchers' => collect(config('sentinel.watchers', []))->map(fn ($options, $class) => [
                'name' => class_basename($class),
                'enabled' => is_array($options) ? (bool) ($options['enabled'] ?? true) : (bool) $options,
            ])->values(),
            'retention' => config('sentinel.retention'),
            'alerts' => [
                'enabled' => (bool) config('sentinel.alerts.enabled'),
                'chats' => count((array) config('sentinel.alerts.chat_ids', [])),
            ],
        ]);
    }

    /**
     * Pause or resume recording.
     *
     * @return \LaraGram\Http\JsonResponse
     */
    public function toggleRecording()
    {
        if (cache('sentinel:pause-recording', false)) {
            cache()->forget('sentinel:pause-recording');
        } else {
            cache()->forever('sentinel:pause-recording', true);
        }

        return response()->json(['recording' => ! cache('sentinel:pause-recording', false)]);
    }

    /**
     * Delete the recorded entries, and optionally the metrics.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return \LaraGram\Http\JsonResponse
     */
    public function clear(Request $request, EntriesRepository $entries, MetricsRepository $metrics)
    {
        $entries->clear();

        if ($request->boolean('metrics')) {
            $metrics->clear();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Start monitoring a tag.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @return \LaraGram\Http\JsonResponse
     */
    public function monitor(Request $request, EntriesRepository $entries)
    {
        $tag = trim((string) $request->input('tag'));

        if ($tag !== '') {
            $entries->monitor([$tag]);
        }

        return response()->json(['monitoring' => $entries->monitoring()]);
    }

    /**
     * Stop monitoring a tag.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @return \LaraGram\Http\JsonResponse
     */
    public function stopMonitoring(Request $request, EntriesRepository $entries)
    {
        $entries->stopMonitoring([(string) $request->input('tag')]);

        return response()->json(['monitoring' => $entries->monitoring()]);
    }
}
