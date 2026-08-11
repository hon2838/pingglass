<?php

namespace App\Services\Monitoring;

class ProbeResult
{
    public function __construct(
        public readonly string $protocol,
        public readonly int $sent,
        public readonly int $received,
        public readonly int $failed,
        public readonly array $samples,
        public readonly ?float $minMs = null,
        public readonly ?float $maxMs = null,
        public readonly ?float $avgMs = null,
        public readonly ?float $medianMs = null,
        public readonly ?float $p10Ms = null,
        public readonly ?float $p25Ms = null,
        public readonly ?float $p75Ms = null,
        public readonly ?float $p90Ms = null,
        public readonly ?float $p95Ms = null,
        public readonly ?float $stddevMs = null,
        public readonly float $lossPercent = 0.0,
        public readonly string $status = 'success',
        public readonly ?string $error = null,
    ) {}

    public static function fromSamples(string $protocol, array $samples, int $timeout): self
    {
        $validSamples = array_filter(
            $samples,
            fn($sample) => $sample !== null && is_numeric($sample) && (float) $sample >= 0,
        );
        $sent = count($samples);
        $received = count($validSamples);
        $failed = $sent - $received;
        $lossPercent = $sent > 0 ? round(($failed / $sent) * 100, 2) : 0;

        if ($received === 0) {
            return new self(
                protocol: $protocol,
                sent: $sent,
                received: 0,
                failed: $failed,
                samples: $samples,
                lossPercent: 100.0,
                status: 'failed',
            );
        }

        sort($validSamples);
        $stats = self::calculateStats($validSamples);

        return new self(
            protocol: $protocol,
            sent: $sent,
            received: $received,
            failed: $failed,
            samples: $samples,
            minMs: $stats['min'],
            maxMs: $stats['max'],
            avgMs: $stats['avg'],
            medianMs: $stats['median'],
            p10Ms: $stats['p10'],
            p25Ms: $stats['p25'],
            p75Ms: $stats['p75'],
            p90Ms: $stats['p90'],
            p95Ms: $stats['p95'],
            stddevMs: $stats['stddev'],
            lossPercent: $lossPercent,
            status: $failed > 0 ? 'partial' : 'success',
        );
    }

    public static function error(string $protocol, string $error): self
    {
        return new self(
            protocol: $protocol,
            sent: 0,
            received: 0,
            failed: 0,
            samples: [],
            status: 'error',
            error: $error,
        );
    }

    private static function calculateStats(array $sorted): array
    {
        $n = count($sorted);
        $sum = array_sum($sorted);
        $avg = $sum / $n;

        $variance = 0;
        foreach ($sorted as $v) {
            $variance += ($v - $avg) ** 2;
        }
        $variance /= $n;

        return [
            'min' => round($sorted[0], 2),
            'max' => round($sorted[$n - 1], 2),
            'avg' => round($avg, 2),
            'median' => round(self::percentile($sorted, 50), 2),
            'p10' => round(self::percentile($sorted, 10), 2),
            'p25' => round(self::percentile($sorted, 25), 2),
            'p75' => round(self::percentile($sorted, 75), 2),
            'p90' => round(self::percentile($sorted, 90), 2),
            'p95' => round(self::percentile($sorted, 95), 2),
            'stddev' => round(sqrt($variance), 2),
        ];
    }

    private static function percentile(array $sorted, int $p): float
    {
        $n = count($sorted);
        $index = ($p / 100) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) return $sorted[$lower];

        $fraction = $index - $lower;
        return $sorted[$lower] * (1 - $fraction) + $sorted[$upper] * $fraction;
    }
}
