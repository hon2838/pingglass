<?php

namespace App\Jobs;

use App\Models\Measurement;
use App\Models\ProbeCycle;
use App\Models\Target;
use App\Models\TargetState;
use App\Services\Monitoring\Drivers\FpingDriver;
use App\Services\Monitoring\Drivers\TcpConnectDriver;
use App\Services\Monitoring\IncidentEvaluator;
use App\Services\Monitoring\ProbeResult;
use App\Services\Monitoring\StatusEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeTargetsChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 110;

    // More than one attempt is required so a prematurely re-delivered Redis
    // payload can defer itself behind the still-running original chunk.
    public int $tries = 3;
    public int $backoff = 5;
    // Must remain below both the probe worker timeout (120s) and Redis
    // retry_after (240s). Production chunks can spend significant time in DNS
    // resolution during their first pass, so 75 seconds is too aggressive.
    public int $timeout = self::TIMEOUT_SECONDS;
    public bool $failOnTimeout = true;

    public function __construct(
        public array $targetIds,
        public int $probeCycleId,
    ) {
        $this->onQueue('probes');
    }

    public function handle(
        FpingDriver $fping,
        TcpConnectDriver $tcp,
        StatusEvaluator $statusEvaluator,
        IncidentEvaluator $incidentEvaluator,
    ): void {
        $lock = null;

        try {
            $candidate = Cache::lock($this->executionLockKey(), self::TIMEOUT_SECONDS + 15);
            if (!$candidate->get()) {
                Log::warning('Duplicate probe chunk delivery deferred', [
                    'cycle_id' => $this->probeCycleId,
                    'target_count' => count($this->targetIds),
                    'attempt' => $this->attempts(),
                ]);
                $this->release(30);
                return;
            }
            $lock = $candidate;
        } catch (Throwable $exception) {
            // Redis/cache health is reported separately. Continue probing so a
            // transient lock-store problem does not discard an entire chunk.
            Log::warning('Probe chunk execution lock unavailable', [
                'cycle_id' => $this->probeCycleId,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $this->processChunk($fping, $tcp, $statusEvaluator, $incidentEvaluator);
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The lock has a short TTL and will expire automatically.
                }
            }
        }
    }

    private function processChunk(
        FpingDriver $fping,
        TcpConnectDriver $tcp,
        StatusEvaluator $statusEvaluator,
        IncidentEvaluator $incidentEvaluator,
    ): void {
        $cycle = ProbeCycle::find($this->probeCycleId);
        if (!$cycle || $cycle->status !== 'running') {
            $this->releaseClaims();
            return;
        }

        $targets = Target::whereIn('id', $this->targetIds)
            ->where('active_probe_cycle_id', $this->probeCycleId)
            ->enabled()
            ->with([
                'state',
                'incidents' => fn($query) => $query->open(),
            ])
            ->get();

        if ($targets->isEmpty()) {
            $this->releaseClaims();
            return;
        }

        $icmpTargets = $targets->filter(fn(Target $target) => $target->icmp_enabled)->values()->all();
        $tcpTargets = $targets->filter(
            fn(Target $target) => $target->tcp_enabled && $target->tcp_port
        )->values()->all();

        $icmpResults = $icmpTargets === [] ? [] : $fping->probeBatch($icmpTargets);
        $tcpResults = $tcpTargets === [] ? [] : $tcp->probeBatch($tcpTargets);

        // Do not write late results into a cycle completed by the timeout sweeper.
        if (ProbeCycle::whereKey($cycle->id)->value('status') !== 'running') {
            $this->releaseClaims();
            return;
        }

        // An administrator may edit/disable a target while sockets are in
        // flight. Only persist results for targets still claimed by this cycle.
        $claimedIds = Target::whereIn('id', $targets->pluck('id'))
            ->where('active_probe_cycle_id', $this->probeCycleId)
            ->pluck('id');
        $targets = $targets->whereIn('id', $claimedIds)->values();
        if ($targets->isEmpty()) {
            $this->releaseClaims();
            return;
        }

        $now = now();
        $measurementRows = [];
        $resultByTarget = [];

        foreach ($targets as $target) {
            $icmpResult = $target->icmp_enabled
                ? ($icmpResults[$target->id] ?? ProbeResult::error('icmp', 'ICMP chunk returned no result'))
                : null;
            $tcpResult = $target->tcp_enabled && $target->tcp_port
                ? ($tcpResults[$target->id] ?? ProbeResult::error('tcp', 'TCP chunk returned no result'))
                : null;

            $resultByTarget[$target->id] = [$icmpResult, $tcpResult];
            if ($icmpResult) {
                $measurementRows[] = $this->measurementRow($target, $cycle, $icmpResult, $now);
            }
            if ($tcpResult) {
                $measurementRows[] = $this->measurementRow($target, $cycle, $tcpResult, $now);
            }
        }

        $existingKeys = Measurement::where('probe_cycle_id', $cycle->id)
            ->whereIn('target_id', $targets->pluck('id'))
            ->get(['target_id', 'protocol'])
            ->mapWithKeys(fn(Measurement $measurement) => [
                "{$measurement->target_id}:{$measurement->protocol}" => true,
            ]);

        Measurement::upsert(
            $measurementRows,
            ['target_id', 'protocol', 'probe_cycle_id'],
            [
                'measured_at', 'sent', 'received', 'loss_percent', 'min_ms',
                'max_ms', 'avg_ms', 'median_ms', 'p10_ms', 'p25_ms',
                'p75_ms', 'p90_ms', 'p95_ms', 'stddev_ms', 'samples',
                'status', 'error', 'updated_at',
            ],
        );

        $states = [];
        $stateRows = [];
        foreach ($targets as $target) {
            [$icmpResult, $tcpResult] = $resultByTarget[$target->id];
            $state = $statusEvaluator->evaluateState($target, $icmpResult, $tcpResult, $target->state);
            $states[$target->id] = $state;
            $stateRows[] = $this->stateRow($state, $now);
        }

        TargetState::upsert(
            $stateRows,
            ['target_id'],
            [
                'overall_status', 'icmp_status', 'icmp_latency_ms', 'icmp_loss_percent',
                'tcp_status', 'tcp_latency_ms', 'tcp_loss_percent', 'last_measured_at',
                'last_status_change_at', 'consecutive_failures', 'consecutive_recoveries',
                'first_failure_at', 'updated_at',
            ],
        );

        foreach ($targets as $target) {
            $state = $states[$target->id];
            $target->setRelation('state', $state);
            $incidentEvaluator->evaluate($target, $state);
        }

        $newRows = array_filter($measurementRows, fn(array $row) =>
            !$existingKeys->has("{$row['target_id']}:{$row['protocol']}")
        );
        $successful = count(array_filter($newRows, fn(array $row) =>
            in_array($row['status'], ['success', 'partial'], true)
        ));
        $failed = count($newRows) - $successful;

        $this->updateCycle($cycle->id, $successful, $failed);
        $this->releaseClaims();

        Log::info('Probe chunk completed', [
            'cycle_id' => $cycle->id,
            'target_count' => $targets->count(),
            'measurement_count' => count($measurementRows),
            'successful' => $successful,
            'failed' => $failed,
        ]);
    }

    private function executionLockKey(): string
    {
        return 'pingglass:probe-chunk:' . $this->probeCycleId . ':'
            . sha1(implode(',', $this->targetIds));
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseClaims();

        Log::error('Probe chunk failed', [
            'cycle_id' => $this->probeCycleId,
            'target_count' => count($this->targetIds),
            'error' => $exception?->getMessage(),
        ]);
    }

    private function measurementRow(Target $target, ProbeCycle $cycle, ProbeResult $result, $now): array
    {
        return [
            'probe_cycle_id' => $cycle->id,
            'target_id' => $target->id,
            'protocol' => $result->protocol,
            'measured_at' => $cycle->started_at,
            'sent' => $result->sent,
            'received' => $result->received,
            'loss_percent' => $result->lossPercent,
            'min_ms' => $result->minMs,
            'max_ms' => $result->maxMs,
            'avg_ms' => $result->avgMs,
            'median_ms' => $result->medianMs,
            'p10_ms' => $result->p10Ms,
            'p25_ms' => $result->p25Ms,
            'p75_ms' => $result->p75Ms,
            'p90_ms' => $result->p90Ms,
            'p95_ms' => $result->p95Ms,
            'stddev_ms' => $result->stddevMs,
            'samples' => json_encode($result->samples),
            'status' => $result->status,
            'error' => $result->error,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function stateRow(TargetState $state, $now): array
    {
        return [
            'target_id' => $state->target_id,
            'overall_status' => $state->overall_status,
            'icmp_status' => $state->icmp_status,
            'icmp_latency_ms' => $state->icmp_latency_ms,
            'icmp_loss_percent' => $state->icmp_loss_percent,
            'tcp_status' => $state->tcp_status,
            'tcp_latency_ms' => $state->tcp_latency_ms,
            'tcp_loss_percent' => $state->tcp_loss_percent,
            'last_measured_at' => $state->last_measured_at,
            'last_status_change_at' => $state->last_status_change_at,
            'consecutive_failures' => $state->consecutive_failures ?? 0,
            'consecutive_recoveries' => $state->consecutive_recoveries ?? 0,
            'first_failure_at' => $state->first_failure_at,
            'created_at' => $state->created_at ?? $now,
            'updated_at' => $now,
        ];
    }

    private function updateCycle(int $cycleId, int $successful, int $failed): void
    {
        DB::transaction(function () use ($cycleId, $successful, $failed) {
            $cycle = ProbeCycle::whereKey($cycleId)->lockForUpdate()->first();
            if (!$cycle || $cycle->status !== 'running') {
                return;
            }

            $cycle->successful_probe_count += $successful;
            $cycle->failed_probe_count += $failed;

            if (($cycle->successful_probe_count + $cycle->failed_probe_count) >= $cycle->expected_probe_count) {
                $cycle->status = 'completed';
                $cycle->completed_at = now();
            }

            $cycle->save();
        });
    }

    private function releaseClaims(): void
    {
        Target::whereIn('id', $this->targetIds)
            ->where('active_probe_cycle_id', $this->probeCycleId)
            ->update(['active_probe_cycle_id' => null]);
    }
}
