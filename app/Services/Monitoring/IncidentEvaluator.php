<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\Target;
use App\Models\TargetState;
use Illuminate\Support\Facades\Log;

class IncidentEvaluator
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    /**
     * Evaluate incidents based on the fresh target state.
     * Called after StatusEvaluator has updated the state.
     */
    public function evaluate(Target $target, TargetState $state): void
    {
        $overall = $state->overall_status;
        $openIncidents = $target->relationLoaded('incidents')
            ? $target->incidents
            : $target->incidents()->open()->get();

        // A completed probe means monitoring is no longer stale even when the
        // remote target itself is degraded, down, or produced a tool error.
        $this->closeMatching(
            $target,
            $openIncidents,
            fn(Incident $incident) => $incident->type === 'monitoring_stale',
            'Monitoring resumed and a fresh probe result was recorded',
        );

        if (in_array($overall, ['down', 'degraded'])) {
            $this->handleProblematic($target, $state, $openIncidents);
        } elseif ($overall === 'online') {
            $this->handleRecovery($target, $openIncidents);
        }
        // 'unknown' doesn't create incidents - the StalenessEvaluator handles that
    }

    /**
     * Evaluate whether a target has gone stale (no recent measurements).
     * Called by the StalenessEvaluator, not by the probe job.
     */
    public function evaluateStale(Target $target, TargetState $state): void
    {
        $openIncidents = $target->relationLoaded('incidents')
            ? $target->incidents
            : $target->incidents()->open()->get();
        $hasStaleIncident = $openIncidents->contains('type', 'monitoring_stale');

        if (!$hasStaleIncident) {
            $incident = Incident::create([
                'target_id' => $target->id,
                'type' => 'monitoring_stale',
                'started_at' => now(),
                'status' => 'open',
                'initial_reason' => 'No measurements received - monitoring may be down',
                'last_reason' => 'No measurements received - monitoring may be down',
            ]);

            Log::warning('Stale monitoring incident created', [
                'target_id' => $target->id,
                'incident_id' => $incident->id,
            ]);
        }
    }

    private function handleProblematic(Target $target, TargetState $state, $openIncidents): void
    {
        $type = $state->overall_status === 'down' ? 'target_down' : 'target_degraded';
        $reason = $this->buildReason($state);

        $this->closeMatching(
            $target,
            $openIncidents,
            fn(Incident $incident) => in_array(
                $incident->type,
                $type === 'target_down'
                    ? ['target_degraded', 'high_latency']
                    : ['target_down'],
                true,
            ),
            "Target status changed to {$state->overall_status}",
        );

        // Check if we already have an incident of this type
        $existing = $openIncidents->first(fn($incident) =>
            $incident->status === 'open'
            && ($incident->type === $type
                || ($type === 'target_degraded' && $incident->type === 'high_latency'))
        );
        if ($existing) {
            $existing->update(['last_reason' => $reason]);
            return;
        }

        // Create new incident
        // Use first_failure_at from state if available, otherwise use now
        $startedAt = $state->first_failure_at ?? now();

        $incident = Incident::create([
            'target_id' => $target->id,
            'type' => $type,
            'protocol' => $this->determineProtocol($state),
            'started_at' => $startedAt,
            'status' => 'open',
            'initial_reason' => $reason,
            'last_reason' => $reason,
        ]);

        Log::info('Incident created', [
            'target_id' => $target->id,
            'incident_id' => $incident->id,
            'type' => $type,
            'reason' => $reason,
        ]);
    }

    private function handleRecovery(Target $target, $openIncidents): void
    {
        foreach ($openIncidents->where('status', 'open') as $incident) {
            // Close all open incidents when target is confirmed online
            $incident->close('Target recovered - all protocols operational');

            Log::info('Incident closed', [
                'target_id' => $target->id,
                'incident_id' => $incident->id,
                'duration_seconds' => $incident->fresh()->duration_seconds,
            ]);
        }
    }

    private function closeMatching(Target $target, $openIncidents, callable $matches, string $reason): void
    {
        foreach ($openIncidents->where('status', 'open')->filter($matches) as $incident) {
            $incident->close($reason);

            Log::info('Incident closed', [
                'target_id' => $target->id,
                'incident_id' => $incident->id,
                'duration_seconds' => $incident->fresh()->duration_seconds,
            ]);
        }
    }

    private function buildReason(TargetState $state): string
    {
        $reasons = [];

        if ($state->icmp_status === 'down') {
            $reasons[] = 'ICMP: 100% packet loss';
        } elseif ($state->icmp_status === 'degraded') {
            $loss = $state->icmp_loss_percent ? number_format($state->icmp_loss_percent, 1) : '?';
            $latency = $state->icmp_latency_ms ? number_format($state->icmp_latency_ms, 1) : '?';
            $reasons[] = "ICMP: {$loss}% loss, {$latency}ms median";
        }

        if ($state->tcp_status === 'down') {
            $reasons[] = 'TCP: unreachable';
        } elseif ($state->tcp_status === 'degraded') {
            $loss = $state->tcp_loss_percent ? number_format($state->tcp_loss_percent, 1) : '?';
            $latency = $state->tcp_latency_ms ? number_format($state->tcp_latency_ms, 1) : '?';
            $reasons[] = "TCP: {$loss}% loss, {$latency}ms median";
        }

        return implode('; ', $reasons) ?: 'Unknown issue';
    }

    private function determineProtocol(TargetState $state): ?string
    {
        if ($state->icmp_status === 'down' && $state->tcp_status === 'down') return null;
        if ($state->icmp_status === 'down') return 'icmp';
        if ($state->tcp_status === 'down') return 'tcp';
        if ($state->icmp_status === 'degraded') return 'icmp';
        if ($state->tcp_status === 'degraded') return 'tcp';
        return null;
    }
}
