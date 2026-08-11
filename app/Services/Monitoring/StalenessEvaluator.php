<?php

namespace App\Services\Monitoring;

use App\Models\Target;
use App\Models\TargetState;
use Illuminate\Support\Facades\Log;

class StalenessEvaluator
{
    public function __construct(
        private SettingsService $settings,
        private IncidentEvaluator $incidentEvaluator,
    ) {}

    /**
     * Evaluate staleness using each target's effective probe interval.
     */
    public function evaluate(): int
    {
        $defaultInterval = max(60, $this->settings->probeInterval());
        $downCycles = max(1, $this->settings->downConfirmationCycles());
        $staleCount = 0;

        Target::enabled()
            ->with([
                'state',
                'incidents' => fn($query) => $query->open(),
            ])
            ->chunkById(500, function ($targets) use ($defaultInterval, $downCycles, &$staleCount) {
                foreach ($targets as $target) {
                    $interval = max(60, $target->probe_interval_seconds ?: $defaultInterval);
                    $cutoff = now()->subSeconds(($interval * $downCycles) + 120);
                    $state = $target->state;
                    $referenceTime = $state?->last_measured_at ?? $target->created_at;

                    if ($referenceTime && $referenceTime->gte($cutoff)) {
                        continue;
                    }

                    if (!$state) {
                        $state = TargetState::firstOrCreate(
                            ['target_id' => $target->id],
                            [
                                'overall_status' => 'unknown',
                                'icmp_status' => 'unknown',
                                'tcp_status' => 'unknown',
                                'last_status_change_at' => now(),
                            ],
                        );
                        $target->setRelation('state', $state);

                        // A probe chunk may have created a fresh state after
                        // this target batch was loaded.
                        if (!$state->wasRecentlyCreated && $state->last_measured_at?->gte($cutoff)) {
                            continue;
                        }

                        $staleCount++;
                    } elseif ($state->overall_status !== 'unknown') {
                        $previousStatus = $state->overall_status;
                        $state->update([
                            'overall_status' => 'unknown',
                            'icmp_status' => 'unknown',
                            'tcp_status' => 'unknown',
                            'last_status_change_at' => now(),
                            'consecutive_failures' => 0,
                            'consecutive_recoveries' => 0,
                            'first_failure_at' => null,
                        ]);
                        $staleCount++;

                        Log::warning('Target marked stale', [
                            'target_id' => $target->id,
                            'previous_status' => $previousStatus,
                            'last_measured_at' => $state->last_measured_at,
                            'effective_interval_seconds' => $interval,
                        ]);
                    }

                    $this->incidentEvaluator->evaluateStale($target, $state);
                }
            });

        return $staleCount;
    }
}
