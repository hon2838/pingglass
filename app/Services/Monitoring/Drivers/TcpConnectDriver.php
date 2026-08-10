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
        $samples = $samples ?? $this->settings->tcpSamples();
        $timeoutMs = $timeout ?? $this->settings->tcpTimeout();
        $host = $target->host;
        $port = $target->tcp_port;

        if (!$port) {
            return ProbeResult::error('tcp', 'No TCP port configured');
        }

        // SSRF: resolve hostname to safe IP before probing
        $resolvedIp = SsrfProtection::resolveForProbe($host);
        if ($resolvedIp === null) {
            return ProbeResult::error('tcp', 'Host resolves to unsafe or unreachable address');
        }

        $results = [];
        for ($i = 0; $i < $samples; $i++) {
            $results[] = $this->tcpPing($resolvedIp, $port, $timeoutMs);
            if ($i < $samples - 1) {
                usleep(50_000);
            }
        }

        return ProbeResult::fromSamples('tcp', $results, $timeoutMs);
    }

    private function tcpPing(string $host, int $port, int $timeoutMs): ?float
    {
        $timeoutSec = $timeoutMs / 1000;
        $startTime = microtime(true);

        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
            $elapsed = (microtime(true) - $startTime) * 1000;

            if ($socket) {
                fclose($socket);
                return round($elapsed, 2);
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }
}
