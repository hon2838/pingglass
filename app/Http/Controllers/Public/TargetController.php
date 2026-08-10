<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Measurement;
use App\Models\MeasurementRollup;
use App\Models\Target;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class TargetController extends Controller
{
    public function show(Target $target)
    {
        // Both target AND its category must be public and enabled
        if (!$target->is_public || !$target->is_enabled) {
            abort(404);
        }

        $target->load(['state', 'category']);

        if (!$target->category || !$target->category->is_public || !$target->category->is_enabled) {
            abort(404);
        }

        $incidents = $target->incidents()
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        // Get latest measurement stats for each protocol
        $latestIcmp = $target->measurements()
            ->where('protocol', 'icmp')
            ->where('status', '!=', 'error')
            ->latest('measured_at')
            ->first();

        $latestTcp = $target->measurements()
            ->where('protocol', 'tcp')
            ->where('status', '!=', 'error')
            ->latest('measured_at')
            ->first();

        return Inertia::render('Public/Target', [
            'target' => array_merge($target->toArrayPublic(), [
                'category_name' => $target->category->name,
                'category_slug' => $target->category->slug,
                'state' => $target->state ? [
                    'overall_status' => $target->state->overall_status,
                    'icmp_status' => $target->state->icmp_status,
                    'icmp_latency_ms' => $target->state->icmp_latency_ms,
                    'icmp_loss_percent' => $target->state->icmp_loss_percent,
                    'tcp_status' => $target->state->tcp_status,
                    'tcp_latency_ms' => $target->state->tcp_latency_ms,
                    'tcp_loss_percent' => $target->state->tcp_loss_percent,
                    'last_measured_at' => $target->state->last_measured_at?->toISOString(),
                ] : null,
                'icmp_stats' => $latestIcmp ? [
                    'avg_ms' => $latestIcmp->avg_ms,
                    'min_ms' => $latestIcmp->min_ms,
                    'max_ms' => $latestIcmp->max_ms,
                    'median_ms' => $latestIcmp->median_ms,
                    'stddev_ms' => $latestIcmp->stddev_ms,
                    'p95_ms' => $latestIcmp->p95_ms,
                    'loss_percent' => $latestIcmp->loss_percent,
                ] : null,
                'tcp_stats' => $latestTcp ? [
                    'avg_ms' => $latestTcp->avg_ms,
                    'min_ms' => $latestTcp->min_ms,
                    'max_ms' => $latestTcp->max_ms,
                    'median_ms' => $latestTcp->median_ms,
                    'stddev_ms' => $latestTcp->stddev_ms,
                    'p95_ms' => $latestTcp->p95_ms,
                    'loss_percent' => $latestTcp->loss_percent,
                ] : null,
            ]),
            'incidents' => $incidents->map(fn($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'protocol' => $i->protocol,
                'started_at' => $i->started_at->toISOString(),
                'ended_at' => $i->ended_at?->toISOString(),
                'status' => $i->status,
                'duration_human' => $i->duration_human,
            ]),
        ]);
    }

    public function metrics(Target $target, Request $request): JsonResponse
    {
        if (!$this->isPubliclyAccessible($target)) {
            abort(404);
        }

        $range = strtolower($request->query('range', '24h'));
        $protocol = $request->query('protocol', 'all');

        [$from, $resolution] = $this->resolveRange($range);

        if ($resolution === 'raw') {
            $data = $this->getRawMetrics($target, $from, $protocol);
        } else {
            $data = $this->getRollupMetrics($target, $from, $resolution, $protocol);
        }

        return response()->json([
            'target' => ['name' => $target->name, 'slug' => $target->slug],
            'range' => $range,
            'resolution' => $resolution,
            'series' => $data,
        ]);
    }

    public function csv(Target $target, Request $request): Response
    {
        if (!$this->isPubliclyAccessible($target)) {
            abort(404);
        }

        $range = strtolower($request->query('range', '24h'));
        $protocol = $request->query('protocol', 'all');

        [$from, $resolution] = $this->resolveRange($range);

        if ($resolution === 'raw') {
            $measurements = Measurement::where('target_id', $target->id)
                ->when($protocol !== 'all', fn($q) => $q->where('protocol', $protocol))
                ->where('measured_at', '>=', $from)
                ->where('status', '!=', 'error')
                ->orderBy('measured_at')
                ->get();
        } else {
            $measurements = MeasurementRollup::where('target_id', $target->id)
                ->when($protocol !== 'all', fn($q) => $q->where('protocol', $protocol))
                ->where('granularity', $resolution)
                ->where('period_start', '>=', $from)
                ->orderBy('period_start')
                ->get();
        }

        $csv = "timestamp,protocol,min_ms,avg_ms,median_ms,max_ms,p95_ms,loss_percent\n";

        foreach ($measurements as $m) {
            $time = $resolution === 'raw'
                ? $m->measured_at->toIso8601String()
                : $m->period_start->toIso8601String();

            $csv .= implode(',', [
                $time,
                $m->protocol,
                $m->min_ms ?? '',
                $m->avg_ms ?? '',
                $m->median_ms ?? '',
                $m->max_ms ?? '',
                $m->p95_ms ?? '',
                $m->loss_percent,
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"pingglass-{$target->slug}-{$range}.csv\"",
        ]);
    }

    private function isPubliclyAccessible(Target $target): bool
    {
        if (!$target->is_public || !$target->is_enabled) return false;
        if (!$target->category || !$target->category->is_public || !$target->category->is_enabled) return false;
        return true;
    }

    private function resolveRange(string $range): array
    {
        // Normalize: accept both '6M' and '6m' for 6 months
        return match ($range) {
            '1h' => [now()->subHour(), 'raw'],
            '6h' => [now()->subHours(6), 'raw'],
            '24h' => [now()->subDay(), 'raw'],
            '7d' => [now()->subDays(7), 'raw'],
            '30d', '30d' => [now()->subDays(30), '5m'],
            '90d' => [now()->subDays(90), '5m'],
            '6m' => [now()->subMonths(6), '1h'],
            '1y' => [now()->subYear(), '1h'],
            '5y' => [now()->subYears(5), '1h'],
            default => [now()->subDay(), 'raw'],
        };
    }

    private function getRawMetrics(Target $target, $from, string $protocol): array
    {
        $series = [];

        $protocols = $this->resolveProtocols($target, $protocol);

        foreach ($protocols as $p) {
            $measurements = Measurement::where('target_id', $target->id)
                ->where('protocol', $p)
                ->where('measured_at', '>=', $from)
                ->where('status', '!=', 'error')
                ->orderBy('measured_at')
                ->get();

            $series[$p] = $measurements->map(fn($m) => [
                'time' => $m->measured_at->toISOString(),
                'min' => $m->min_ms,
                'max' => $m->max_ms,
                'avg' => $m->avg_ms,
                'median' => $m->median_ms,
                'p10' => $m->p10_ms,
                'p25' => $m->p25_ms,
                'p75' => $m->p75_ms,
                'p90' => $m->p90_ms,
                'p95' => $m->p95_ms,
                'loss' => $m->loss_percent,
            ])->values();
        }

        return $series;
    }

    private function getRollupMetrics(Target $target, $from, string $granularity, string $protocol): array
    {
        $series = [];

        $protocols = $this->resolveProtocols($target, $protocol);

        foreach ($protocols as $p) {
            $rollups = MeasurementRollup::where('target_id', $target->id)
                ->where('protocol', $p)
                ->where('granularity', $granularity)
                ->where('period_start', '>=', $from)
                ->orderBy('period_start')
                ->get();

            $series[$p] = $rollups->map(fn($r) => [
                'time' => $r->period_start->toISOString(),
                'min' => $r->min_ms,
                'max' => $r->max_ms,
                'avg' => $r->avg_ms,
                'median' => $r->median_ms,
                'p10' => $r->p10_ms,
                'p25' => $r->p25_ms,
                'p75' => $r->p75_ms,
                'p90' => $r->p90_ms,
                'p95' => $r->p95_ms,
                'loss' => $r->loss_percent,
            ])->values();
        }

        return $series;
    }

    private function resolveProtocols(Target $target, string $protocol): array
    {
        if ($protocol === 'all') {
            return array_filter([
                $target->icmp_enabled ? 'icmp' : null,
                $target->tcp_enabled ? 'tcp' : null,
            ]);
        }

        return [$protocol];
    }
}
