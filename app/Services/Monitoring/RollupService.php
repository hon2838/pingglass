<?php

namespace App\Services\Monitoring;

use App\Models\Measurement;
use App\Models\MeasurementRollup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RollupService
{
    private const FIVE_MIN_CHECKPOINT_KEY = 'pingglass:rollup:5m:last_period';
    private const HOURLY_CHECKPOINT_KEY = 'pingglass:rollup:1h:last_period';

    /**
     * Aggregate raw measurements into 5-minute rollups.
     * Uses a persistent checkpoint to recover from outages longer than the lookback window.
     */
    public function aggregateFiveMinute(): int
    {
        $now = now();

        // Start from checkpoint or default lookback
        $checkpoint = Cache::get(self::FIVE_MIN_CHECKPOINT_KEY);
        if ($checkpoint instanceof Carbon) {
            $lookback = $this->alignToFiveMinutes($checkpoint);
        } else {
            $lookback = $this->alignToFiveMinutes($now->copy()->subHour()->startOfMinute());
        }

        $created = 0;
        $cursor = $lookback->copy();
        $lastProcessed = null;

        // Use copy() in condition to avoid mutating cursor
        while ($cursor->copy()->addMinutes(5)->lte($now)) {
            $start = $cursor->copy();
            $end = $cursor->copy()->addMinutes(5);

            $this->aggregateWindow('5m', $start, $end);
            $created++;
            $lastProcessed = $end->copy();

            $cursor = $end;
        }

        // Save checkpoint forever — survives cache flushes and long outages
        if ($lastProcessed) {
            Cache::forever(self::FIVE_MIN_CHECKPOINT_KEY, $lastProcessed);
        }

        return $created;
    }

    /**
     * Aggregate raw measurements into hourly rollups.
     * Uses a persistent checkpoint to recover from outages longer than the lookback window.
     */
    public function aggregateHourly(): int
    {
        $now = now();

        $checkpoint = Cache::get(self::HOURLY_CHECKPOINT_KEY);
        if ($checkpoint instanceof Carbon) {
            $lookback = $checkpoint->copy()->startOfHour();
        } else {
            $lookback = $now->copy()->subHours(25)->startOfHour();
        }

        $created = 0;
        $cursor = $lookback->copy();
        $lastProcessed = null;

        while ($cursor->copy()->addHour()->lte($now)) {
            $start = $cursor->copy();
            $end = $cursor->copy()->addHour();

            $this->aggregateWindow('1h', $start, $end);
            $created++;
            $lastProcessed = $end->copy();

            $cursor = $end;
        }

        if ($lastProcessed) {
            Cache::forever(self::HOURLY_CHECKPOINT_KEY, $lastProcessed);
        }

        return $created;
    }

    /**
     * Aggregate a single time window. Idempotent via updateOrCreate.
     */
    private function aggregateWindow(string $granularity, Carbon $from, Carbon $to): void
    {
        $measurements = Measurement::where('measured_at', '>=', $from)
            ->where('measured_at', '<', $to)
            ->where('status', '!=', 'error')
            ->get()
            ->groupBy(fn($m) => "{$m->target_id}_{$m->protocol}");

        foreach ($measurements as $key => $group) {
            [$targetId, $protocol] = explode('_', $key, 2);
            $targetId = (int) $targetId;

            $allSamples = [];
            $totalSent = 0;
            $totalReceived = 0;

            foreach ($group as $m) {
                $totalSent += $m->sent;
                $totalReceived += $m->received;
                if (is_array($m->samples)) {
                    foreach ($m->samples as $s) {
                        if ($s !== null) $allSamples[] = $s;
                    }
                }
            }

            if ($totalSent === 0) continue;

            $lossPercent = round((($totalSent - $totalReceived) / $totalSent) * 100, 2);

            sort($allSamples);
            $n = count($allSamples);

            $stats = [];
            if ($n > 0) {
                $sum = array_sum($allSamples);
                $avg = $sum / $n;
                $variance = 0;
                foreach ($allSamples as $v) {
                    $variance += ($v - $avg) ** 2;
                }
                $variance /= $n;

                $stats = [
                    'min_ms' => round($allSamples[0], 2),
                    'max_ms' => round($allSamples[$n - 1], 2),
                    'avg_ms' => round($avg, 2),
                    'median_ms' => round($this->percentile($allSamples, 50), 2),
                    'p10_ms' => round($this->percentile($allSamples, 10), 2),
                    'p25_ms' => round($this->percentile($allSamples, 25), 2),
                    'p75_ms' => round($this->percentile($allSamples, 75), 2),
                    'p90_ms' => round($this->percentile($allSamples, 90), 2),
                    'p95_ms' => round($this->percentile($allSamples, 95), 2),
                    'stddev_ms' => round(sqrt($variance), 2),
                ];
            }

            MeasurementRollup::updateOrCreate(
                [
                    'target_id' => $targetId,
                    'protocol' => $protocol,
                    'granularity' => $granularity,
                    'period_start' => $from,
                ],
                array_merge([
                    'sent' => $totalSent,
                    'received' => $totalReceived,
                    'loss_percent' => $lossPercent,
                ], $stats)
            );
        }
    }

    private function alignToFiveMinutes(Carbon $dt): Carbon
    {
        $minute = $dt->minute - ($dt->minute % 5);
        return $dt->copy()->minute($minute)->second(0);
    }

    private function percentile(array $sorted, int $p): float
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
