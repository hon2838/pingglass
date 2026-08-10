<?php

namespace App\Jobs;

use App\Models\Measurement;
use App\Models\ProbeCycle;
use App\Models\Target;
use App\Services\Monitoring\Drivers\FpingDriver;
use App\Services\Monitoring\Drivers\TcpConnectDriver;
use App\Services\Monitoring\IncidentEvaluator;
use App\Services\Monitoring\ProbeResult;
use App\Services\Monitoring\SettingsService;
use App\Services\Monitoring\StatusEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProbeTarget implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'probes';

    public function __construct(
        public int $targetId,
        public int $probeCycleId,
    ) {}

    public function handle(
        FpingDriver $fping,
        TcpConnectDriver $tcp,
        StatusEvaluator $statusEval,
        IncidentEvaluator $incidentEval,
        SettingsService $settings,
    ): void {
        $target = Target::find($this->targetId);
        $cycle = ProbeCycle::find($this->probeCycleId);

        if (!$target || !$cycle) return;
        if (!$target->is_enabled) return;
        if ($cycle->status !== 'running') return;

        // Per-target lock: prevent concurrent probes on the same target from different cycles
        $lock = Cache::lock("probe-target-{$this->targetId}", 30);
        if (!$lock->get()) {
            Log::info('Target probe skipped - lock held', [
                'target_id' => $this->targetId,
                'cycle_id' => $this->probeCycleId,
            ]);
            return;
        }

        $icmpResult = null;
        $tcpResult = null;

        try {
            if ($target->icmp_enabled) {
                $icmpResult = $fping->probe($target);
                $this->storeMeasurement($target, $cycle, $icmpResult);
                $this->updateCycleCounters($cycle, $icmpResult);
            }

            if ($target->tcp_enabled && $target->tcp_port) {
                $tcpResult = $tcp->probe($target);
                $this->storeMeasurement($target, $cycle, $tcpResult);
                $this->updateCycleCounters($cycle, $tcpResult);
            }

            // Evaluate status and incidents using fresh state
            $state = $statusEval->evaluate($target, $icmpResult, $tcpResult);
            $incidentEval->evaluate($target, $state);

            Log::info('Probe completed', [
                'cycle_id' => $cycle->id,
                'target_id' => $target->id,
                'target_name' => $target->name,
                'icmp_status' => $icmpResult?->status,
                'icmp_loss' => $icmpResult?->lossPercent,
                'tcp_status' => $tcpResult?->status,
                'tcp_loss' => $tcpResult?->lossPercent,
            ]);

        } catch (\Exception $e) {
            Log::error('Probe failed', [
                'target_id' => $target->id,
                'cycle_id' => $cycle->id,
                'error' => $e->getMessage(),
            ]);

            $this->storeErrorMeasurement($target, $cycle, 'icmp', $e->getMessage());
            $this->updateCycleCounters($cycle, ProbeResult::error('icmp', $e->getMessage()));
        } finally {
            $lock->release();
        }
    }

    private function storeMeasurement(Target $target, ProbeCycle $cycle, ProbeResult $result): void
    {
        Measurement::create([
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
            'samples' => $result->samples,
            'status' => $result->status,
            'error' => $result->error,
        ]);
    }

    private function storeErrorMeasurement(Target $target, ProbeCycle $cycle, string $protocol, string $error): void
    {
        Measurement::create([
            'probe_cycle_id' => $cycle->id,
            'target_id' => $target->id,
            'protocol' => $protocol,
            'measured_at' => $cycle->started_at,
            'sent' => 0,
            'received' => 0,
            'loss_percent' => 100,
            'status' => 'error',
            'error' => $error,
        ]);
    }

    private function updateCycleCounters(ProbeCycle $cycle, ProbeResult $result): void
    {
        $field = ($result->status === 'error' || $result->status === 'failed')
            ? 'failed_probe_count'
            : 'successful_probe_count';

        // Atomically increment and check if cycle is complete
        DB::transaction(function () use ($cycle, $field) {
            ProbeCycle::where('id', $cycle->id)->lockForUpdate()->increment($field);

            // Reload to get fresh counter values
            $fresh = ProbeCycle::where('id', $cycle->id)->first();
            $totalDone = $fresh->successful_probe_count + $fresh->failed_probe_count;

            if ($totalDone >= $fresh->expected_probe_count && $fresh->status === 'running') {
                $fresh->update([
                    'completed_at' => now(),
                    'status' => 'completed',
                ]);

                Log::info('Probe cycle completed', [
                    'cycle_id' => $fresh->id,
                    'successful' => $fresh->successful_probe_count,
                    'failed' => $fresh->failed_probe_count,
                    'duration' => $fresh->started_at->diffInSeconds(now()),
                ]);
            }
        });
    }
}
