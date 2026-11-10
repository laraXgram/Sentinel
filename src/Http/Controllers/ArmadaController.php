<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\Docker\Armada;
use Throwable;

class ArmadaController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Docker\Armada  $armada
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     */
    public function __construct(
        protected Armada $armada,
        protected MetricsRepository $metrics,
    ) {
    }

    /**
     * Every service of the project, with the usage history sentinel:check recorded.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\JsonResponse
     */
    public function index(Request $request)
    {
        if (! $this->armada->enabled()) {
            return response()->json(['enabled' => false]);
        }

        $period = $this->period($request);

        $graph = function (string $type, string $aggregate = 'avg') use ($period) {
            $graph = collect($this->metrics->graph([$type], $aggregate, $period)[$type] ?? []);
            $buckets = array_keys((array) ($graph->first(fn ($values) => ! empty($values)) ?? []));

            return ['buckets' => $buckets, 'series' => $graph->map(fn ($values) => array_values((array) $values))->all()];
        };

        return response()->json(['enabled' => true] + $this->armada->overview() + [
            'cpu' => $graph('armada_cpu'),
            'memory' => $graph('armada_memory', 'max'),
        ]);
    }

    /**
     * One service: its configuration, health checks, processes and logs.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $service
     * @return \LaraGram\Http\JsonResponse
     */
    public function show(Request $request, string $service)
    {
        $lines = min(2000, max(10, (int) $request->query('lines', config('sentinel.armada.log_lines', 300))));

        try {
            return response()->json($this->armada->service($service, $lines) + ['actions' => $this->armada->allowsActions()]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * Start, stop or restart the container of a service.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $service
     * @param  string  $action
     * @return \LaraGram\Http\JsonResponse
     */
    public function action(Request $request, string $service, string $action)
    {
        if (! $this->armada->allowsActions()) {
            return response()->json(['message' => 'Container actions are disabled.'], 403);
        }

        try {
            $this->armada->action($service, $action);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Start, stop or restart a process of the application container.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $process
     * @param  string  $action
     * @return \LaraGram\Http\JsonResponse
     */
    public function process(Request $request, string $process, string $action)
    {
        if (! $this->armada->allowsActions()) {
            return response()->json(['message' => 'Process actions are disabled.'], 403);
        }

        try {
            $output = $this->armada->processAction($process, $action);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'output' => $output]);
    }
}
