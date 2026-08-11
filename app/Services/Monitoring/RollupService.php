<?php

namespace App\Services\Monitoring;

use App\Models\Measurement;
use App\Models\MeasurementRollup;
use App\Models\ScopeMeasurementRollup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RollupService
{
    private const FIVE_MIN_CHECKPOINT_KEY = 'pingglass:rollup:5m:last_period';
    private const HOURLY_CHECKPOINT_KEY = 'pingglass:rollup:1h:last_period';

    public function aggregateFiveMinute(): int
    {
        $now = now();
        $checkpoint = Cache::get(self::FIVE_MIN_CHECKPOINT_KEY);
        $cursor = $checkpoint instanceof Carbon
            ? $this->alignToFiveMinutes($checkpoint)
            : $this->alignToFiveMinutes($now->copy()->subHour()->startOfMinute());
        $processed = 0;
        $lastProcessed = null;

        while ($cursor->copy()->addMinutes(5)->lte($now)) {
            $start = $cursor->copy();
            $end = $start->copy()->addMinutes(5);
            $this->aggregateWindow('5m', $start, $end);
            $this->aggregateScopeWindow('5m', $start);
            $processed++;
            $lastProcessed = $end->copy();
            $cursor = $end;
        }

        if ($lastProcessed) {
            Cache::forever(self::FIVE_MIN_CHECKPOINT_KEY, $lastProcessed);
        }

        return $processed;
    }

    public function aggregateHourly(): int
    {
        $now = now();
        $checkpoint = Cache::get(self::HOURLY_CHECKPOINT_KEY);
        $cursor = $checkpoint instanceof Carbon
            ? $checkpoint->copy()->startOfHour()
            : $now->copy()->subHours(25)->startOfHour();
        $processed = 0;
        $lastProcessed = null;

        while ($cursor->copy()->addHour()->lte($now)) {
            $start = $cursor->copy();
            $end = $start->copy()->addHour();
            $this->aggregateWindow('1h', $start, $end);
            $this->aggregateScopeWindow('1h', $start);
            $processed++;
            $lastProcessed = $end->copy();
            $cursor = $end;
        }

        if ($lastProcessed) {
            Cache::forever(self::HOURLY_CHECKPOINT_KEY, $lastProcessed);
        }

        return $processed;
    }

    public function backfillScopeRollups(int $hours = 24): int
    {
        $processed = 0;
        foreach (['5m', '1h'] as $granularity) {
            $periods = MeasurementRollup::where('granularity', $granularity)
                ->where('period_start', '>=', now()->subHours($hours))
                ->distinct()
                ->orderBy('period_start')
                ->pluck('period_start');

            foreach ($periods as $period) {
                $this->aggregateScopeWindow($granularity, Carbon::parse($period));
                $processed++;
            }
        }

        return $processed;
    }

    private function aggregateWindow(string $granularity, Carbon $from, Carbon $to): void
    {
        $targetIds = Measurement::where('measured_at', '>=', $from)
            ->where('measured_at', '<', $to)
            ->where('status', '!=', 'error')
            ->distinct()
            ->orderBy('target_id')
            ->pluck('target_id');

        foreach ($targetIds->chunk(200) as $idChunk) {
            $groups = Measurement::whereIn('target_id', $idChunk)
                ->where('measured_at', '>=', $from)
                ->where('measured_at', '<', $to)
                ->where('status', '!=', 'error')
                ->get()
                ->groupBy(fn(Measurement $measurement) => "{$measurement->target_id}:{$measurement->protocol}");

            $rows = [];
            foreach ($groups as $group) {
                $rows[] = $this->buildTargetRollupRow($group, $granularity, $from);
            }

            if ($rows !== []) {
                MeasurementRollup::upsert(
                    $rows,
                    ['target_id', 'protocol', 'granularity', 'period_start'],
                    [
                        'sent', 'received', 'loss_percent', 'min_ms', 'max_ms',
                        'avg_ms', 'median_ms', 'p10_ms', 'p25_ms', 'p75_ms',
                        'p90_ms', 'p95_ms', 'stddev_ms', 'updated_at',
                    ],
                );
            }
        }
    }

    private function buildTargetRollupRow($group, string $granularity, Carbon $periodStart): array
    {
        $samples = [];
        $sent = 0;
        $received = 0;

        foreach ($group as $measurement) {
            $sent += $measurement->sent;
            $received += $measurement->received;
            foreach ($measurement->samples ?? [] as $sample) {
                if ($sample !== null) {
                    $samples[] = (float) $sample;
                }
            }
        }

        sort($samples);
        $stats = $this->calculateStats($samples);
        $now = now();

        return array_merge([
            'target_id' => $group->first()->target_id,
            'protocol' => $group->first()->protocol,
            'granularity' => $granularity,
            'period_start' => $periodStart,
            'sent' => $sent,
            'received' => $received,
            'loss_percent' => $sent > 0 ? round((($sent - $received) / $sent) * 100, 2) : 100,
            'created_at' => $now,
            'updated_at' => $now,
        ], $stats);
    }

    private function aggregateScopeWindow(string $granularity, Carbon $periodStart): void
    {
        $aggregateColumns = "
            mr.protocol,
            COUNT(*) as target_count,
            SUM(mr.sent) as sent,
            SUM(mr.received) as received,
            MIN(mr.min_ms) as min_ms,
            MAX(mr.max_ms) as max_ms,
            SUM(CASE WHEN mr.avg_ms IS NOT NULL THEN mr.avg_ms * mr.received ELSE 0 END)
                / NULLIF(SUM(CASE WHEN mr.avg_ms IS NOT NULL THEN mr.received ELSE 0 END), 0) as avg_ms,
            AVG(mr.median_ms) as median_ms,
            AVG(mr.p10_ms) as p10_ms,
            AVG(mr.p25_ms) as p25_ms,
            AVG(mr.p75_ms) as p75_ms,
            AVG(mr.p90_ms) as p90_ms,
            AVG(mr.p95_ms) as p95_ms,
            AVG(mr.stddev_ms) as stddev_ms
        ";

        $base = DB::table('measurement_rollups as mr')
            ->join('targets as t', 't.id', '=', 'mr.target_id')
            ->join('categories as c', 'c.id', '=', 't.category_id')
            ->where('mr.granularity', $granularity)
            ->where('mr.period_start', $periodStart)
            ->where('t.is_enabled', true)
            ->where('t.is_public', true)
            ->where('c.is_enabled', true)
            ->where('c.is_public', true);

        $categoryRows = (clone $base)
            ->selectRaw("t.category_id, {$aggregateColumns}")
            ->groupBy('t.category_id', 'mr.protocol')
            ->get();
        $globalRows = (clone $base)
            ->selectRaw("NULL as category_id, {$aggregateColumns}")
            ->groupBy('mr.protocol')
            ->get();

        $rows = [];
        foreach ($categoryRows->concat($globalRows) as $aggregate) {
            $sent = (int) $aggregate->sent;
            $received = (int) $aggregate->received;
            $categoryId = $aggregate->category_id !== null ? (int) $aggregate->category_id : null;
            $now = now();

            $rows[] = [
                'scope_key' => $categoryId === null ? 'global' : "category:{$categoryId}",
                'category_id' => $categoryId,
                'protocol' => $aggregate->protocol,
                'granularity' => $granularity,
                'period_start' => $periodStart,
                'target_count' => (int) $aggregate->target_count,
                'sent' => $sent,
                'received' => $received,
                'loss_percent' => $sent > 0 ? round((($sent - $received) / $sent) * 100, 2) : 100,
                'min_ms' => $this->roundNullable($aggregate->min_ms),
                'max_ms' => $this->roundNullable($aggregate->max_ms),
                'avg_ms' => $this->roundNullable($aggregate->avg_ms),
                'median_ms' => $this->roundNullable($aggregate->median_ms),
                'p10_ms' => $this->roundNullable($aggregate->p10_ms),
                'p25_ms' => $this->roundNullable($aggregate->p25_ms),
                'p75_ms' => $this->roundNullable($aggregate->p75_ms),
                'p90_ms' => $this->roundNullable($aggregate->p90_ms),
                'p95_ms' => $this->roundNullable($aggregate->p95_ms),
                'stddev_ms' => $this->roundNullable($aggregate->stddev_ms),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            ScopeMeasurementRollup::upsert(
                $rows,
                ['scope_key', 'protocol', 'granularity', 'period_start'],
                [
                    'category_id', 'target_count', 'sent', 'received', 'loss_percent',
                    'min_ms', 'max_ms', 'avg_ms', 'median_ms', 'p10_ms', 'p25_ms',
                    'p75_ms', 'p90_ms', 'p95_ms', 'stddev_ms', 'updated_at',
                ],
            );
        }
    }

    private function calculateStats(array $samples): array
    {
        if ($samples === []) {
            return array_fill_keys([
                'min_ms', 'max_ms', 'avg_ms', 'median_ms', 'p10_ms', 'p25_ms',
                'p75_ms', 'p90_ms', 'p95_ms', 'stddev_ms',
            ], null);
        }

        $count = count($samples);
        $average = array_sum($samples) / $count;
        $variance = array_sum(array_map(fn(float $value) => ($value - $average) ** 2, $samples)) / $count;

        return [
            'min_ms' => round($samples[0], 2),
            'max_ms' => round($samples[$count - 1], 2),
            'avg_ms' => round($average, 2),
            'median_ms' => round($this->percentile($samples, 50), 2),
            'p10_ms' => round($this->percentile($samples, 10), 2),
            'p25_ms' => round($this->percentile($samples, 25), 2),
            'p75_ms' => round($this->percentile($samples, 75), 2),
            'p90_ms' => round($this->percentile($samples, 90), 2),
            'p95_ms' => round($this->percentile($samples, 95), 2),
            'stddev_ms' => round(sqrt($variance), 2),
        ];
    }

    private function percentile(array $sorted, int $percent): float
    {
        $index = ($percent / 100) * (count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) return $sorted[$lower];
        $fraction = $index - $lower;
        return $sorted[$lower] * (1 - $fraction) + $sorted[$upper] * $fraction;
    }

    private function alignToFiveMinutes(Carbon $date): Carbon
    {
        $minute = $date->minute - ($date->minute % 5);
        return $date->copy()->minute($minute)->second(0);
    }

    private function roundNullable($value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
