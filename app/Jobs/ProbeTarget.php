<?php

namespace App\Jobs;

use App\Models\Measurement;
use App\Models\ProbeCycle;
use App\Models\Target;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProbeTarget implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $targetId,
        public int $probeCycleId,
    ) {
        $this->onQueue('probes');
    }

    /**
     * Handle TCP probing for a target.
     * ICMP is handled by BatchIcmpProbe. This job only does TCP.
     * After TCP completes, it evaluates combined status (ICMP result is already stored).
     */
    public function handle(
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

        if (!$target->tcp_enabled || !$target->tcp_port) return;

        try {
            $tcpResult = $tcp->probe($target);
            $this->storeMeasurement($target, $cycle, $tcpResult);
            $this->updateCycleCounters($cycle, $tcpResult);

            // Load the ICMP result that was already stored by BatchIcmpProbe
            $icmpMeasurement = Measurement::where('target_id', $target->id)
                ->where('probe_cycle_id', $cycle->id)
                ->where('protocol', 'icmp')
                ->first();

            $icmpResult = null;
            if ($icmpMeasurement && $icmpMeasurement->status !== 'error') {
                $icmpResult = ProbeResult::fromSamples('icmp', $icmpMeasurement->samples ?? [], $settings->icmpTimeout());
            }

            // Evaluate combined ICMP + TCP status and incidents
            $state = $statusEval->evaluate($target, $icmpResult, $tcpResult);
            $incidentEval->evaluate($target, $state);

            Log::info('TCP probe completed', [
                'cycle_id' => $cycle->id,
                'target_id' => $target->id,
                'target_name' => $target->name,
                'tcp_status' => $tcpResult->status,
                'tcp_loss' => $tcpResult->lossPercent,
                'tcp_median' => $tcpResult->medianMs,
            ]);

        } catch (\Exception $e) {
            Log::error('TCP probe failed', [
                'target_id' => $target->id,
                'cycle_id' => $cycle->id,
                'error' => $e->getMessage(),
            ]);

            $this->storeErrorMeasurement($target, $cycle, 'tcp', $e->getMessage());
            $this->updateCycleCounters($cycle, ProbeResult::error('tcp', $e->getMessage()));
        }
    }

    private function storeMeasurement(Target $target, ProbeCycle $cycle, ProbeResult $result): void
    {
        Measurement::create([
            'probe_cycle_id' => $cycle->id,
            'target_id' => $target->id,
            'protocol' => 'tcp',
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

        DB::transaction(function () use ($cycle, $field) {
            ProbeCycle::where('id', $cycle->id)->lockForUpdate()->increment($field);

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
