<?php

namespace App\Jobs;

use App\Models\Measurement;
use App\Models\ProbeCycle;
use App\Models\Target;
use App\Services\Monitoring\Drivers\FpingDriver;
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

class BatchIcmpProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public array $targetIds,
        public int $probeCycleId,
    ) {
        $this->onQueue('probes');
    }

    public function handle(
        FpingDriver $fping,
        StatusEvaluator $statusEval,
        IncidentEvaluator $incidentEval,
        SettingsService $settings,
    ): void {
        $cycle = ProbeCycle::find($this->probeCycleId);
        if (!$cycle || $cycle->status !== 'running') return;

        $targets = Target::whereIn('id', $this->targetIds)
            ->enabled()
            ->get()
            ->keyBy('id');

        if ($targets->isEmpty()) return;

        Log::info('Batch ICMP probe starting', [
            'cycle_id' => $cycle->id,
            'target_count' => $targets->count(),
        ]);

        // Batched fping — internally splits into chunks of 250
        $results = $fping->probeBatch($targets->values()->all());

        foreach ($results as $targetId => $result) {
            $target = $targets->get($targetId);
            if (!$target) continue;

            $this->storeMeasurement($target, $cycle, $result);
            $this->updateCycleCounters($cycle, $result);

            // Evaluate status — pass ICMP result, and TCP result if the target also does TCP
            $tcpResult = null;
            if ($target->tcp_enabled && $target->tcp_port) {
                // TCP will be handled by a separate ProbeTarget job
                // Only evaluate ICMP status here
                $tcpResult = null;
            }

            $state = $statusEval->evaluate($target, $result, $tcpResult);
            if (!$target->tcp_enabled) {
                // Only evaluate incidents if this target has no TCP job coming
                $incidentEval->evaluate($target, $state);
            }

            Log::info('Batch ICMP probe completed', [
                'cycle_id' => $cycle->id,
                'target_id' => $target->id,
                'target_name' => $target->name,
                'status' => $result->status,
                'loss' => $result->lossPercent,
                'median' => $result->medianMs,
            ]);
        }
    }

    private function storeMeasurement(Target $target, ProbeCycle $cycle, ProbeResult $result): void
    {
        Measurement::create([
            'probe_cycle_id' => $cycle->id,
            'target_id' => $target->id,
            'protocol' => 'icmp',
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

    private function updateCycleCounters(ProbeCycle $cycle, ProbeResult $result): void
    {
        $field = ($result->status === 'error' || $result->status === 'failed')
            ? 'failed_probe_count'
            : 'successful_probe_count';

        ProbeCycle::where('id', $cycle->id)->increment($field);
    }
}
