<?php

namespace App\Services\Monitoring\Drivers;

use App\Models\Target;
use App\Services\Monitoring\Contracts\ProbeDriver;
use App\Services\Monitoring\ProbeResult;
use App\Services\Monitoring\SettingsService;
use App\Services\Monitoring\SsrfProtection;

class TcpConnectDriver implements ProbeDriver
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    public function probe(Target $target, ?int $samples = null, ?int $timeout = null): ProbeResult
    {
        $samples ??= $this->settings->tcpSamples();
        $timeoutMs = $timeout ?? $this->settings->tcpTimeout();

        if (!$target->tcp_port) {
            return ProbeResult::error('tcp', 'No TCP port configured');
        }

        $resolvedIp = SsrfProtection::resolveForProbe($target->host);
        if ($resolvedIp === null) {
            return ProbeResult::error('tcp', 'Host resolves to unsafe or unreachable address');
        }

        $sampleValues = [];
        for ($round = 0; $round < $samples; $round++) {
            $roundResults = $this->connectRound([
                0 => ['ip' => $resolvedIp, 'port' => (int) $target->tcp_port],
            ], $timeoutMs);
            $sampleValues[] = $roundResults[0] ?? null;

            if ($round < $samples - 1) {
                usleep(50_000);
            }
        }

        return ProbeResult::fromSamples('tcp', $sampleValues, $timeoutMs);
    }

    /**
     * Run one TCP connection sample round for every target concurrently, then
     * repeat for the configured number of samples.
     *
     * @param array<int, Target> $targets
     * @return array<int, ProbeResult>
     */
    public function probeBatch(array $targets, ?int $samples = null, ?int $timeout = null): array
    {
        $samples ??= $this->settings->tcpSamples();
        $timeoutMs = $timeout ?? $this->settings->tcpTimeout();

        $endpoints = [];
        $sampleValues = [];
        $results = [];

        foreach ($targets as $target) {
            if (!$target->tcp_port) {
                $results[$target->id] = ProbeResult::error('tcp', 'No TCP port configured');
                continue;
            }

            $resolvedIp = SsrfProtection::resolveForProbe($target->host);
            if ($resolvedIp === null) {
                $results[$target->id] = ProbeResult::error('tcp', 'Host resolves to unsafe or unreachable address');
                continue;
            }

            $endpoints[$target->id] = [
                'ip' => $resolvedIp,
                'port' => (int) $target->tcp_port,
            ];
            $sampleValues[$target->id] = [];
        }

        for ($round = 0; $round < $samples && $endpoints !== []; $round++) {
            $roundResults = $this->connectRound($endpoints, $timeoutMs);
            foreach ($endpoints as $targetId => $_endpoint) {
                $sampleValues[$targetId][] = $roundResults[$targetId] ?? null;
            }

            if ($round < $samples - 1) {
                usleep(50_000);
            }
        }

        foreach ($sampleValues as $targetId => $values) {
            $results[$targetId] = ProbeResult::fromSamples('tcp', $values, $timeoutMs);
        }

        return $results;
    }

    /**
     * @param array<int, array{ip: string, port: int}> $endpoints
     * @return array<int, ?float>
     */
    private function connectRound(array $endpoints, int $timeoutMs): array
    {
        $pending = [];
        $results = array_fill_keys(array_keys($endpoints), null);

        foreach ($endpoints as $targetId => $endpoint) {
            $host = filter_var($endpoint['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? "[{$endpoint['ip']}]"
                : $endpoint['ip'];
            $address = "tcp://{$host}:{$endpoint['port']}";
            $startedAt = microtime(true);

            $stream = @stream_socket_client(
                $address,
                $errorCode,
                $errorMessage,
                $timeoutMs / 1000,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($stream === false) {
                continue;
            }

            stream_set_blocking($stream, false);
            $pending[(int) $stream] = [
                'stream' => $stream,
                'target_id' => $targetId,
                'started_at' => $startedAt,
            ];
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);

        while ($pending !== [] && microtime(true) < $deadline) {
            $read = [];
            $write = array_map(fn(array $item) => $item['stream'], $pending);
            $except = $write;
            $remaining = max(0.001, $deadline - microtime(true));
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);

            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false || $selected === 0) {
                break;
            }

            $failedStreams = array_fill_keys(array_map('intval', $except), true);
            foreach ($write as $stream) {
                $resourceId = (int) $stream;
                $item = $pending[$resourceId] ?? null;
                if ($item === null) {
                    continue;
                }

                $connected = !isset($failedStreams[$resourceId]) && $this->socketConnected($stream);
                if ($connected) {
                    $results[$item['target_id']] = round((microtime(true) - $item['started_at']) * 1000, 2);
                }

                fclose($stream);
                unset($pending[$resourceId]);
            }

            foreach ($except as $stream) {
                $resourceId = (int) $stream;
                if (!isset($pending[$resourceId])) {
                    continue;
                }
                fclose($stream);
                unset($pending[$resourceId]);
            }
        }

        foreach ($pending as $item) {
            fclose($item['stream']);
        }

        return $results;
    }

    private function socketConnected($stream): bool
    {
        if (function_exists('socket_import_stream')) {
            $socket = @socket_import_stream($stream);
            if ($socket !== false) {
                $error = @socket_get_option($socket, SOL_SOCKET, SO_ERROR);
                if (is_int($error) && $error !== 0) {
                    return false;
                }
            }
        }

        return @stream_socket_get_name($stream, true) !== false;
    }
}
