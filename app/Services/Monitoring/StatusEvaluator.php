<?php

namespace App\Services\Monitoring;

use App\Models\Target;
use App\Models\TargetState;
use Illuminate\Support\Facades\Log;

class StatusEvaluator
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    /**
     * Evaluate the current measurement results and update target state.
     * Returns the updated TargetState for downstream use by IncidentEvaluator.
     */
    public function evaluate(
        Target $target,
        ?ProbeResult $icmpResult,
        ?ProbeResult $tcpResult,
        ?TargetState $existingState = null,
    ): TargetState
    {
        $state = $this->evaluateState($target, $icmpResult, $tcpResult, $existingState);
        $state->save();
        return $state;
    }

    /**
     * Calculate a state without writing it. Chunk jobs use this to bulk-upsert
     * all target states in one database statement.
     */
    public function evaluateState(
        Target $target,
        ?ProbeResult $icmpResult,
        ?ProbeResult $tcpResult,
        ?TargetState $existingState = null,
    ): TargetState
    {
        $icmpStatus = $this->evaluateProtocol($icmpResult, $target);
        $tcpStatus = $this->evaluateProtocol($tcpResult, $target);

        // Determine the "raw" overall status from this cycle's results
        $rawOverall = $this->determineRawOverall($target, $icmpStatus, $tcpStatus);

        // Load or create state
        $state = $existingState
            ?? ($target->relationLoaded('state')
                ? new TargetState(['target_id' => $target->id])
                : TargetState::firstOrNew(['target_id' => $target->id]));
        $previousOverall = $state->overall_status ?? 'unknown';

        // Update per-protocol metrics
        $state->icmp_status = $icmpStatus;
        $state->icmp_latency_ms = $icmpResult?->medianMs;
        $state->icmp_loss_percent = $icmpResult?->lossPercent;
        $state->tcp_status = $tcpStatus;
        $state->tcp_latency_ms = $tcpResult?->medianMs;
        $state->tcp_loss_percent = $tcpResult?->lossPercent;
        $state->last_measured_at = now();

        // Apply confirmation logic for overall status
        $confirmedOverall = $this->applyConfirmation($state, $rawOverall, $previousOverall);

        // Only update last_status_change_at if overall status actually changed
        if ($confirmedOverall !== $previousOverall) {
            $state->last_status_change_at = now();
            Log::info('Status changed', [
                'target_id' => $target->id,
                'from' => $previousOverall,
                'to' => $confirmedOverall,
            ]);
        }

        $state->overall_status = $confirmedOverall;
        return $state;
    }

    private function evaluateProtocol(?ProbeResult $result, ?Target $target = null): string
    {
        if (!$result) return 'unknown';
        if ($result->status === 'error') return 'unknown';
        if ($result->lossPercent >= 100) return 'down';

        // Per-target thresholds override global defaults
        $lossThreshold = $target?->loss_threshold_percent ?? $this->settings->lossThresholdPercent();
        if ($result->lossPercent > 0 && $result->lossPercent >= $lossThreshold) return 'degraded';

        $latencyThreshold = $target?->latency_threshold_ms ?? $this->settings->latencyThresholdMs();
        if ($result->medianMs !== null && $result->medianMs > $latencyThreshold) return 'degraded';

        return 'online';
    }

    /**
     * Determine the raw overall status from this cycle's per-protocol results.
     * This does NOT apply confirmation logic yet.
     */
    private function determineRawOverall(Target $target, string $icmp, string $tcp): string
    {
        $statuses = [];
        if ($target->icmp_enabled) $statuses[] = $icmp;
        if ($target->tcp_enabled) $statuses[] = $tcp;

        if (empty($statuses)) return 'unknown';

        if (count(array_filter($statuses, fn($status) => $status === 'down')) === count($statuses)) {
            return 'down';
        }

        if (in_array('online', $statuses, true)) {
            return count(array_filter($statuses, fn($status) => $status === 'online')) === count($statuses)
                ? 'online'
                : 'degraded';
        }

        // A degraded protocol is still reachable, so it prevents an overall
        // DOWN result even when another protocol has failed completely.
        if (in_array('degraded', $statuses, true)) return 'degraded';

        // A tool/DNS error must never preserve or manufacture ONLINE/DOWN.
        if (in_array('unknown', $statuses, true)) return 'unknown';

        return 'unknown';
    }

    /**
     * Apply cycle-based confirmation logic.
     * - DOWN requires N consecutive failed cycles
     * - RECOVERY requires M consecutive successful cycles
     * - Unknown from probe errors doesn't count as failure
     */
    private function applyConfirmation(TargetState $state, string $raw, string $previous): string
    {
        $downRequired = $this->settings->downConfirmationCycles();
        $recoveryRequired = $this->settings->recoveryConfirmationCycles();

        if ($raw === 'down') {
            $state->consecutive_recoveries = 0;
            $state->consecutive_failures = ($state->consecutive_failures ?? 0) + 1;

            if ($state->first_failure_at === null) {
                $state->first_failure_at = now();
            }

            if ($state->consecutive_failures >= $downRequired) {
                return 'down';
            }

            if ($previous === 'down') return 'down';
            if (in_array($previous, ['online', 'degraded'], true)) return 'degraded';
            return 'unknown';
        }

        if ($raw === 'degraded') {
            // Degradation is immediate but must not count toward DOWN.
            $state->consecutive_failures = 0;
            $state->consecutive_recoveries = 0;
            $state->first_failure_at ??= now();
            return 'degraded';
        }

        if ($raw === 'online') {
            // If we were in a problem state, require recovery confirmation
            if (in_array($previous, ['down', 'degraded'])) {
                $state->consecutive_failures = 0;
                $state->consecutive_recoveries = ($state->consecutive_recoveries ?? 0) + 1;
                $state->first_failure_at = null;

                if ($state->consecutive_recoveries >= $recoveryRequired) {
                    return 'online';
                }

                // Not enough recovery confirmations yet
                return $previous;
            }

            // Normal operation - reset everything
            $state->consecutive_failures = 0;
            $state->consecutive_recoveries = 0;
            $state->first_failure_at = null;
            return 'online';
        }

        // Probe/tool errors are UNKNOWN immediately and break confirmation
        // streaks. StalenessEvaluator separately handles probes not running.
        $state->consecutive_failures = 0;
        $state->consecutive_recoveries = 0;
        $state->first_failure_at = null;
        return 'unknown';
    }
}
