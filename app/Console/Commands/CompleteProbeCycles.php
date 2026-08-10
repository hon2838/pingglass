<?php

namespace App\Console\Commands;

use App\Models\ProbeCycle;
use Illuminate\Console\Command;

class CompleteProbeCycles extends Command
{
    protected $signature = 'pingglass:complete-cycles';
    protected $description = 'Mark stale probe cycles as completed and update counters';

    public function handle(): int
    {
        // Complete cycles that have been running for more than 5 minutes
        $stale = ProbeCycle::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(5))
            ->get();

        foreach ($stale as $cycle) {
            $this->completeCycle($cycle);
            $this->info("Completed cycle #{$cycle->id}");
        }

        // Also try to eagerly complete cycles where all expected probes are done
        $recentRunning = ProbeCycle::where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes(5))
            ->get();

        foreach ($recentRunning as $cycle) {
            $totalMeasurements = $cycle->measurements()->count();
            if ($totalMeasurements >= $cycle->expected_probe_count) {
                $this->completeCycle($cycle);
                $this->info("Eagerly completed cycle #{$cycle->id}");
            }
        }

        return self::SUCCESS;
    }

    private function completeCycle(ProbeCycle $cycle): void
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
            'status' => 'completed',
            'successful_probe_count' => $successful + $partial,
            'failed_probe_count' => $failed,
        ]);
    }
}
