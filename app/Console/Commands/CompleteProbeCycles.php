<?php

namespace App\Console\Commands;

use App\Models\ProbeCycle;
use App\Models\Target;
use Illuminate\Console\Command;

class CompleteProbeCycles extends Command
{
    protected $signature = 'pingglass:complete-cycles';
    protected $description = 'Complete finished probe cycles and time out abandoned cycles';

    public function handle(): int
    {
        // Close cycles that have been running for more than 5 minutes. An
        // incomplete cycle is timed out, never reported as completed.
        $stale = ProbeCycle::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(5))
            ->get();

        foreach ($stale as $cycle) {
            $this->completeCycle($cycle, true);
            $this->info("Timed out cycle #{$cycle->id}");
        }

        // Also try to eagerly complete cycles where all expected probes are done
        $recentRunning = ProbeCycle::where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes(5))
            ->get();

        foreach ($recentRunning as $cycle) {
            $totalMeasurements = $cycle->measurements()->count();
            if ($totalMeasurements >= $cycle->expected_probe_count) {
                $this->completeCycle($cycle, false);
                $this->info("Eagerly completed cycle #{$cycle->id}");
            }
        }

        return self::SUCCESS;
    }

    private function completeCycle(ProbeCycle $cycle, bool $timedOut): void
    {
        $successful = $cycle->measurements()
            ->where('status', 'success')
            ->count();

        $partial = $cycle->measurements()
            ->where('status', 'partial')
            ->count();

        $failed = $cycle->measurements()
            ->whereIn('status', ['failed', 'error'])
            ->count();

        $cycle->update([
            'completed_at' => now(),
            'status' => $timedOut ? 'timed_out' : 'completed',
            'successful_probe_count' => $successful + $partial,
            'failed_probe_count' => $failed,
        ]);

        if ($timedOut) {
            Target::where('active_probe_cycle_id', $cycle->id)
                ->update(['active_probe_cycle_id' => null]);
        }
    }
}
