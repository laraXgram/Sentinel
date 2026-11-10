<?php

namespace LaraGram\Sentinel\Docker;

use RuntimeException;

/**
 * A small client of the Docker Engine API, over the Docker socket or TCP
 * (a socket proxy, or a daemon listening on a port).
 */
class DockerClient
{
    /**
     * The API version every request is made with; Docker 20.10 and newer speak it.
     */
    public const API_VERSION = 'v1.41';

    /**
     * Create a new client.
     *
     * @param  string  $host  unix:///var/run/docker.sock, tcp://host:2375 or http(s)://host:port
     * @param  int  $timeout
     */
    public function __construct(
        protected string $host,
        protected int $timeout = 5,
    ) {
    }

    /**
     * Get the address of the Docker daemon.
     *
     * @return string
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Get the version of the Docker daemon.
     *
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return $this->json('GET', '/version');
    }

    /**
     * Get the containers that match the given label filters.
     *
     * @param  array<int, string>  $labels  "key" or "key=value"
     * @return array<int, array<string, mixed>>
     */
    public function containers(array $labels = []): array
    {
        $query = ['all' => 'true'];

        if ($labels !== []) {
            $query['filters'] = json_encode(['label' => array_values($labels)]);
        }

        return $this->json('GET', '/containers/json', $query);
    }

    /**
     * Inspect a container.
     *
     * @param  string  $id
     * @return array<string, mixed>
     */
    public function inspect(string $id): array
    {
        return $this->json('GET', '/containers/'.rawurlencode($id).'/json');
    }

    /**
     * Get one sample of the resource usage of a running container.
     *
     * @param  string  $id
     * @return array<string, mixed>
     */
    public function stats(string $id): array
    {
        return $this->json('GET', '/containers/'.rawurlencode($id).'/stats', ['stream' => 'false', 'one-shot' => 'true']);
    }

    /**
     * Get the last lines a container wrote to its output.
     *
     * @param  string  $id
     * @param  int  $tail
     * @param  bool  $tty  Whether the container has a TTY, which writes its output unframed.
     * @return array<int, array{stream: string, time: string|null, line: string}>
     */
    public function logs(string $id, int $tail = 200, bool $tty = false): array
    {
        $raw = $this->request('GET', '/containers/'.rawurlencode($id).'/logs', [
            'stdout' => 'true',
            'stderr' => 'true',
            'timestamps' => 'true',
            'tail' => (string) max(1, $tail),
        ])['body'];

        $lines = [];

        foreach ($tty ? [['stdout', $raw]] : static::demultiplex($raw) as [$stream, $text]) {
            foreach (preg_split('/\r?\n/', rtrim($text, "\n")) as $line) {
                if ($line === '') {
                    continue;
                }

                [$time, $message] = str_contains($line, ' ') && preg_match('/^\d{4}-\d\d-\d\dT/', $line)
                    ? explode(' ', $line, 2)
                    : [null, $line];

                $lines[] = ['stream' => $stream, 'time' => $time, 'line' => $message];
            }
        }

        return $lines;
    }

    /**
     * Start, stop or restart a container.
     *
     * @param  string  $id
     * @param  string  $action
     * @return void
     */
    public function action(string $id, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new RuntimeException("Unsupported container action [{$action}].");
        }

        $query = $action === 'start' ? [] : ['t' => '10'];

        $this->request('POST', '/containers/'.rawurlencode($id).'/'.$action, $query, null, 30);
    }

    /**
     * Run a command in a running container and get its output and exit code.
     *
     * @param  string  $id
     * @param  array<int, string>  $command
     * @return array{output: string, exit_code: int|null}
     */
    public function exec(string $id, array $command): array
    {
        $exec = $this->json('POST', '/containers/'.rawurlencode($id).'/exec', [], [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd' => array_values($command),
        ]);

        $raw = $this->request('POST', '/exec/'.rawurlencode($exec['Id']).'/start', [], ['Detach' => false, 'Tty' => false], 30)['body'];

        $output = implode('', array_column(static::demultiplex($raw), 1));

        $result = $this->json('GET', '/exec/'.rawurlencode($exec['Id']).'/json');

        return ['output' => $output, 'exit_code' => $result['ExitCode'] ?? null];
    }

    /**
     * Split the output of a container without a TTY into its stdout and stderr frames.
     *
     * Each frame starts with a header of eight bytes: the stream, three zero
     * bytes and the big-endian length of the frame.
     *
     * @param  string  $raw
     * @return array<int, array{0: string, 1: string}>
     */
    public static function demultiplex(string $raw): array
    {
        $frames = [];
        $offset = 0;
        $length = strlen($raw);

        while ($offset + 8 <= $length) {
            $stream = ord($raw[$offset]);
            $size = unpack('N', substr($raw, $offset + 4, 4))[1];

            if ($stream > 2 || substr($raw, $offset + 1, 3) !== "\0\0\0") {
                // not a framed stream after all
                return [['stdout', $raw]];
            }

            $frames[] = [$stream === 2 ? 'stderr' : 'stdout', substr($raw, $offset + 8, $size)];
            $offset += 8 + $size;
        }

        return $frames === [] && $raw !== '' ? [['stdout', $raw]] : $frames;
    }

    /**
     * Make a request and decode its JSON answer.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>
     */
    protected function json(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->request($method, $path, $query, $body);

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Make a request to the Docker daemon.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     * @param  int|null  $timeout
     * @return array{status: int, body: string}
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null, ?int $timeout = null): array
    {
        $url = '/'.static::API_VERSION.$path.($query === [] ? '' : '?'.http_build_query($query));

        $handle = curl_init();

        if (str_starts_with($this->host, 'unix://')) {
            curl_setopt($handle, CURLOPT_UNIX_SOCKET_PATH, substr($this->host, 7));
            $url = 'http://docker'.$url;
        } else {
            $url = rtrim(preg_replace('#^tcp://#', 'http://', $this->host), '/').$url;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, '');
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException("Docker is not reachable at [{$this->host}]: {$error}");
        }

        if ($status >= 400) {
            $message = json_decode($response, true)['message'] ?? trim($response);

            throw new RuntimeException("Docker answered {$status}: {$message}");
        }

        return ['status' => $status, 'body' => $response];
    }
}
