<?php

namespace App\Services\Monitoring\Drivers;

use App\Models\Target;
use App\Services\Monitoring\Contracts\ProbeDriver;
use App\Services\Monitoring\ProbeResult;
use App\Services\Monitoring\SettingsService;
use App\Services\Monitoring\SsrfProtection;
use Illuminate\Support\Facades\Process;

class FpingDriver implements ProbeDriver
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    public function probe(Target $target, ?int $samples = null, ?int $timeout = null): ProbeResult
    {
        $samples ??= $this->settings->icmpSamples();
        $timeoutMs = $timeout ?? $this->settings->icmpTimeout();

        if (!preg_match('/^[a-zA-Z0-9.\-:]+$/', $target->host)) {
            return ProbeResult::error('icmp', 'Invalid host format');
        }

        $resolvedIp = SsrfProtection::resolveForProbe($target->host);
        if ($resolvedIp === null) {
            return ProbeResult::error('icmp', 'Host resolves to unsafe or unreachable address');
        }

        return $this->probeIps([$resolvedIp], $samples, $timeoutMs)[$resolvedIp]
            ?? ProbeResult::error('icmp', 'No response from fping');
    }

    /**
     * Probe one bounded queue chunk and return results keyed by target ID.
     * Duplicate resolved IPs are probed once and copied to every logical target.
     *
     * @param array<int, Target> $targets
     * @return array<int, ProbeResult>
     */
    public function probeBatch(array $targets, ?int $samples = null, ?int $timeout = null): array
    {
        $samples ??= $this->settings->icmpSamples();
        $timeoutMs = $timeout ?? $this->settings->icmpTimeout();

        $ipToTargetIds = [];
        $results = [];

        foreach ($targets as $target) {
            if (!preg_match('/^[a-zA-Z0-9.\-:]+$/', $target->host)) {
                $results[$target->id] = ProbeResult::error('icmp', 'Invalid host format');
                continue;
            }

            $resolvedIp = SsrfProtection::resolveForProbe($target->host);
            if ($resolvedIp === null) {
                $results[$target->id] = ProbeResult::error('icmp', 'Host resolves to unsafe or unreachable address');
                continue;
            }

            $ipToTargetIds[$resolvedIp] ??= [];
            $ipToTargetIds[$resolvedIp][] = $target->id;
        }

        if ($ipToTargetIds === []) {
            return $results;
        }

        $ipResults = $this->probeIps(array_keys($ipToTargetIds), $samples, $timeoutMs);

        foreach ($ipToTargetIds as $ip => $targetIds) {
            $ipResult = $ipResults[$ip] ?? ProbeResult::error('icmp', 'No response from fping');
            foreach ($targetIds as $targetId) {
                $results[$targetId] = $ipResult;
            }
        }

        return $results;
    }

    /**
     * @param array<int, string> $ips
     * @return array<string, ProbeResult>
     */
    private function probeIps(array $ips, int $samples, int $timeoutMs): array
    {
        $fpingPath = config('pingglass.fping_path', '/usr/bin/fping');
        $intervalMs = max(1, (int) config('pingglass.fping_interval_ms', 1));
        // fping -p is the interval for one target; -i is the global send
        // interval. Its documented behavior is inconsistent when -t > -p.
        $periodMs = max(10, $timeoutMs);

        $command = [
            $fpingPath,
            '-C', (string) $samples,
            '-i', (string) $intervalMs,
            '-p', (string) $periodMs,
            '-t', (string) $timeoutMs,
            '-q',
        ];

        $sendWindowMs = (max(0, $samples - 1) * $periodMs) + (count($ips) * $intervalMs);
        $processTimeout = (($sendWindowMs + $timeoutMs) / 1000) + 15;

        try {
            // stdin avoids command-line length limits and fping's root-only -f.
            $process = Process::input(implode("\n", $ips) . "\n")
                ->timeout($processTimeout)
                ->run($command);

            if ($process->exitCode() >= 2) {
                $message = trim($process->errorOutput()) ?: trim($process->output());
                return $this->errorResults(
                    $ips,
                    "fping error (exit {$process->exitCode()}): {$message}",
                );
            }

            $output = trim($process->errorOutput()) !== ''
                ? $process->errorOutput()
                : $process->output();

            return $this->parseBatchOutput($output, $ips, $samples, $timeoutMs);
        } catch (\Throwable $e) {
            return $this->errorResults($ips, $e->getMessage());
        }
    }

    /**
     * @param array<int, string> $expectedIps
     * @return array<string, ProbeResult>
     */
    private function parseBatchOutput(
        string $output,
        array $expectedIps,
        int $expectedSamples,
        int $timeoutMs,
    ): array
    {
        $expected = array_fill_keys($expectedIps, true);
        $results = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'duplicate') || str_starts_with($line, 'ICMP')) {
                continue;
            }

            if (!preg_match('/^(\S+)\s*:\s*(.+)$/', $line, $matches)) {
                continue;
            }

            $ip = $matches[1];
            if (!isset($expected[$ip])) {
                continue;
            }

            $samples = $this->parseSummaryTokens($matches[2]);
            $samples = $this->normalizeSamples($samples, $expectedSamples);
            $results[$ip] = ProbeResult::fromSamples('icmp', $samples, $timeoutMs);
        }

        foreach ($expectedIps as $ip) {
            $results[$ip] ??= ProbeResult::error('icmp', 'No response from fping');
        }

        return $results;
    }

    private function parseSummaryTokens(string $valueString): array
    {
        $samples = [];
        foreach (preg_split('/\s+/', trim($valueString)) as $token) {
            $normalized = strtolower($token);
            if ($token === '-' || $normalized === 'timeout' || $normalized === 'unreachable') {
                $samples[] = null;
            } elseif (is_numeric($token)) {
                $samples[] = (float) $token;
            }
        }

        return $samples;
    }

    private function normalizeSamples(array $samples, int $expected): array
    {
        if (count($samples) < $expected) {
            return array_merge($samples, array_fill(0, $expected - count($samples), null));
        }

        return array_slice($samples, 0, $expected);
    }

    /**
     * @param array<int, string> $ips
     * @return array<string, ProbeResult>
     */
    private function errorResults(array $ips, string $message): array
    {
        $results = [];
        foreach ($ips as $ip) {
            $results[$ip] = ProbeResult::error('icmp', $message);
        }

        return $results;
    }
}
