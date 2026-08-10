<?php

namespace App\Services\Monitoring;

use App\Models\Target;
use App\Models\TargetState;
use Illuminate\Support\Facades\Log;

class StalenessEvaluator
{
    public function __construct(
        private SettingsService $settings,
        private IncidentEvaluator $incidentEval,
    ) {}

    /**
     * Check all enabled targets for stale measurements.
     * A target is stale if its last_measured_at is older than
     * (probe_interval * down_confirmation_cycles) + buffer.
     *
     * This should run every few minutes via the scheduler.
     */
    public function evaluate(): int
    {
        $probeInterval = $this->settings->probeInterval();
        $downCycles = $this->settings->downConfirmationCycles();

        // Stale threshold: expected interval * confirmation cycles + 2 minutes buffer
        $staleThresholdMinutes = (($probeInterval / 60) * $downCycles) + 2;
        $staleCutoff = now()->subMinutes($staleThresholdMinutes);

        // Include targets with stale states OR targets without state that were created before the cutoff
        $targets = Target::enabled()
            ->where(function ($q) use ($staleCutoff) {
                $q->whereHas('state', function ($sq) use ($staleCutoff) {
                    $sq->where('last_measured_at', '<', $staleCutoff)
                       ->orWhereNull('last_measured_at');
                })
                ->orWhere(function ($sq) use ($staleCutoff) {
                    $sq->whereDoesntHave('state')
                       ->where('created_at', '<', $staleCutoff);
                });
            })
            ->with('state')
            ->get();

        $staleCount = 0;

        foreach ($targets as $target) {
            $state = $target->state;
            if (!$state) {
                // Target has no state and was created before the cutoff
                $state = TargetState::create([
                    'target_id' => $target->id,
                    'overall_status' => 'unknown',
                    'icmp_status' => 'unknown',
                    'tcp_status' => 'unknown',
                ]);
                $this->incidentEval->evaluateStale($target, $state);
                $staleCount++;
                Log::warning('New target marked unknown (no measurements)', [
                    'target_id' => $target->id,
                ]);
                continue;
            }

            // Mark any non-unknown target as unknown when monitoring has stopped
            // This includes 'down' — if the monitor itself stops, we can't confirm
            // the target is still down, so it must become unknown.
            if ($state->overall_status !== 'unknown') {
                $state->update([
                    'overall_status' => 'unknown',
                    'icmp_status' => 'unknown',
                    'tcp_status' => 'unknown',
                ]);

                $this->incidentEval->evaluateStale($target, $state);
                $staleCount++;

                Log::warning('Target marked stale', [
                    'target_id' => $target->id,
                    'previous_status' => $state->getOriginal('overall_status'),
                    'last_measured_at' => $state->last_measured_at,
                ]);
            } else {
                // Already unknown - ensure stale incident exists
                $this->incidentEval->evaluateStale($target, $state);
            }
        }

        return $staleCount;
    }
}
