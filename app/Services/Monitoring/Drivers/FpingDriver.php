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
        $samples = $samples ?? $this->settings->icmpSamples();
        $timeoutMs = $timeout ?? $this->settings->icmpTimeout();
        $fpingPath = config('pingglass.fping_path', '/usr/bin/fping');
        $host = $target->host;

        if (!preg_match('/^[a-zA-Z0-9.\-:]+$/', $host)) {
            return ProbeResult::error('icmp', 'Invalid host format');
        }

        // SSRF: resolve hostname to safe IP before probing
        $resolvedIp = SsrfProtection::resolveForProbe($host);
        if ($resolvedIp === null) {
            return ProbeResult::error('icmp', 'Host resolves to unsafe or unreachable address');
        }
        $probeTarget = $resolvedIp;

        // fping -C N -q outputs summary to stderr in format:
        //   host : val1 val2 val3 - val5
        // where `-` means timeout/loss
        //
        // -C N: send N pings
        // -q: quiet mode, only print summary
        // -t: per-ping timeout in ms
        // -p: interval between pings in ms
        //
        // IMPORTANT: fping docs state timeout should NOT exceed the period (-p).
        // We set period = timeout so each ping gets a full timeout window before
        // the next one is sent. This is slower but correct.
        $command = [
            $fpingPath,
            '-C', (string) $samples,
            '-p', (string) $timeoutMs,
            '-t', (string) $timeoutMs,
            '-q',
            $probeTarget,
        ];

        $totalTimeout = ($timeoutMs / 1000 * $samples) + 15;

        try {
            $result = Process::timeout($totalTimeout)
                ->run($command);

            $exitCode = $result->exitCode();
            $stdout = $result->output();
            $stderr = $result->errorOutput();

            // Exit codes:
            // 0 = all targets reachable
            // 1 = some targets unreachable
            // 2 = usage error (bad arguments)
            // 3 = system error (cannot create socket, etc.)
            // 4 = DNS resolution error
            if ($exitCode >= 2) {
                $errorMsg = trim($stderr) ?: trim($stdout);
                return ProbeResult::error('icmp', "fping system error (exit {$exitCode}): {$errorMsg}");
            }

            // fping -C -q outputs the summary to stderr
            // Format: host : val1 val2 val3 - val5
            // Try stderr first (where -q puts the summary), fall back to stdout
            $output = trim($stderr) !== '' ? $stderr : $stdout;

            return $this->parseOutput($output, $samples, $host, $exitCode);
        } catch (\Exception $e) {
            return ProbeResult::error('icmp', $e->getMessage());
        }
    }

    /**
     * Parse fping -C -q output.
     *
     * Summary format (stderr):
     *   host : val1 val2 val3 - val5
     *
     * Individual format (stdout, when -q is NOT used):
     *   host : [0] 31.2 ms
     *   host : [1] timeout
     *
     * We try the summary format first, then fall back to individual format.
     * `-` or `timeout` or `unreachable` → null
     */
    private function parseOutput(string $output, int $expectedSamples, string $host, int $exitCode): ProbeResult
    {
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Skip informational lines
            if (str_contains($line, 'duplicate')) continue;
            if (str_starts_with($line, 'ICMP')) continue;

            // Try summary format first: "host : val1 val2 - val4"
            if (preg_match('/^\S+\s*:\s*(.+)$/', $line, $m)) {
                $valuesStr = trim($m[1]);

                // Check if this is individual format: "host : [N] 31.2 ms"
                if (preg_match('/^\[\d+\]\s/', $valuesStr)) {
                    return $this->parseIndividualFormat($output, $expectedSamples, $host, $exitCode);
                }

                // Summary format: space-separated values
                return $this->parseSummaryFormat($valuesStr, $expectedSamples, $exitCode);
            }
        }

        // If we couldn't parse anything, try individual format
        return $this->parseIndividualFormat($output, $expectedSamples, $host, $exitCode);
    }

    /**
     * Parse summary format: "31.2 29.8 - 35.1 30.0"
     * where `-` means timeout
     */
    private function parseSummaryFormat(string $valuesStr, int $expectedSamples, int $exitCode): ProbeResult
    {
        $tokens = preg_split('/\s+/', trim($valuesStr));
        $samples = [];

        foreach ($tokens as $token) {
            if ($token === '-' || strtolower($token) === 'timeout' || strtolower($token) === 'unreachable') {
                $samples[] = null;
            } elseif (is_numeric($token)) {
                $samples[] = (float) $token;
            }
            // Skip any other tokens (error messages, etc.)
        }

        if (empty($samples)) {
            return ProbeResult::error('icmp', 'No samples parsed from fping output');
        }

        // Pad or truncate to expected count
        $samples = $this->normalizeSamples($samples, $expectedSamples);

        return ProbeResult::fromSamples('icmp', $samples, $this->settings->icmpTimeout());
    }

    /**
     * Parse individual format (without -q):
     *   host : [0] 31.2 ms
     *   host : [1] timeout
     *   host : [2] unreachable
     */
    private function parseIndividualFormat(string $output, int $expectedSamples, string $host, int $exitCode): ProbeResult
    {
        $samples = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_contains($line, 'duplicate')) continue;
            if (str_starts_with($line, 'ICMP')) continue;

            // Successful response: host : [N] 31.2 ms
            if (preg_match('/:\s*\[\d+\]\s*([\d.]+)\s*ms/', $line, $m)) {
                $samples[] = (float) $m[1];
                continue;
            }

            // Timeout or unreachable
            if (preg_match('/:\s*\[\d+\]\s*(timeout|unreachable|-)/i', $line)) {
                $samples[] = null;
                continue;
            }
        }

        if (empty($samples)) {
            return ProbeResult::error('icmp', 'No samples parsed from fping output');
        }

        $samples = $this->normalizeSamples($samples, $expectedSamples);

        return ProbeResult::fromSamples('icmp', $samples, $this->settings->icmpTimeout());
    }

    /**
     * Pad with nulls or truncate to match expected sample count.
     */
    private function normalizeSamples(array $samples, int $expected): array
    {
        $count = count($samples);
        if ($count < $expected) {
            return array_merge($samples, array_fill(0, $expected - $count, null));
        }
        if ($count > $expected) {
            return array_slice($samples, 0, $expected);
        }
        return $samples;
    }
}
