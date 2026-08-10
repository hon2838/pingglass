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
    public function evaluate(Target $target, ?ProbeResult $icmpResult, ?ProbeResult $tcpResult): TargetState
    {
        $icmpStatus = $this->evaluateProtocol($icmpResult, $target);
        $tcpStatus = $this->evaluateProtocol($tcpResult, $target);

        // Determine the "raw" overall status from this cycle's results
        $rawOverall = $this->determineRawOverall($target, $icmpStatus, $tcpStatus);

        // Load or create state
        $state = TargetState::firstOrNew(['target_id' => $target->id]);
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
        $state->save();

        return $state;
    }

    private function evaluateProtocol(?ProbeResult $result, ?Target $target = null): string
    {
        if (!$result) return 'unknown';
        if ($result->status === 'error') return 'unknown';
        if ($result->lossPercent >= 100) return 'down';

        // Per-target thresholds override global defaults
        $lossThreshold = $target?->loss_threshold_percent ?? config('pingglass.degraded.loss_threshold_percent', 10);
        if ($result->lossPercent >= $lossThreshold) return 'degraded';

        $latencyThreshold = $target?->latency_threshold_ms ?? config('pingglass.degraded.latency_threshold_ms', 200);
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

        // If both are unknown, it's unknown
        if (count(array_filter($statuses, fn($s) => $s !== 'unknown')) === 0) return 'unknown';

        // If any is down and none are online, it's down
        if (in_array('down', $statuses) && !in_array('online', $statuses)) return 'down';

        // If any is down but some are online, it's degraded
        if (in_array('down', $statuses) && in_array('online', $statuses)) return 'degraded';

        // If any is degraded, it's degraded
        if (in_array('degraded', $statuses)) return 'degraded';

        // Mixed unknown and online: degraded (partial monitoring)
        if (in_array('unknown', $statuses) && in_array('online', $statuses)) return 'degraded';

        return 'online';
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

        if ($raw === 'down' || $raw === 'degraded') {
            // Reset recovery counter, increment failure counter
            $state->consecutive_recoveries = 0;
            $state->consecutive_failures = ($state->consecutive_failures ?? 0) + 1;

            if ($state->first_failure_at === null) {
                $state->first_failure_at = now();
            }

            // Only transition to down if we have enough consecutive failures
            if ($raw === 'down' && $state->consecutive_failures >= $downRequired) {
                return 'down';
            }

            // Degraded is reported immediately (no confirmation needed for degraded)
            if ($raw === 'degraded') {
                return 'degraded';
            }

            // Not enough confirmations yet - keep previous status if it was online
            if ($previous === 'online' || $previous === 'unknown') {
                return 'online';
            }

            return $previous;
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

        // Unknown - don't change counters, keep previous status
        // But if stale for too long, the StalenessEvaluator will handle it
        return $previous;
    }
}
