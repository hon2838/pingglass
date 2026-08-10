<?php

namespace App\Console\Commands;

use App\Jobs\ProbeTarget;
use App\Models\ProbeCycle;
use App\Models\Target;
use App\Services\Monitoring\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunProbeCycle extends Command
{
    protected $signature = 'pingglass:probe-cycle';
    protected $description = 'Run a probe cycle against all enabled targets';

    public function handle(SettingsService $settings): int
    {
        $lock = Cache::lock('probe-cycle-lock', 60);

        if (!$lock->get()) {
            $this->warn('Probe cycle lock held, skipping.');
            return self::SUCCESS;
        }

        try {
            // Mark any stale running cycles as timed out
            $staleCycles = ProbeCycle::where('status', 'running')
                ->where('started_at', '<', now()->subSeconds(90))
                ->get();

            foreach ($staleCycles as $stale) {
                $stale->update([
                    'status' => 'timed_out',
                    'completed_at' => now(),
                ]);
                $this->warn("Marked stale cycle #{$stale->id} as timed_out.");
            }

            // Check if a recent cycle is still actively running
            $activeCycle = ProbeCycle::where('status', 'running')
                ->where('started_at', '>=', now()->subSeconds(90))
                ->first();

            if ($activeCycle) {
                $this->warn("Cycle #{$activeCycle->id} still running (started {$activeCycle->started_at->diffForHumans()}), skipping.");
                return self::SUCCESS;
            }

            $targets = Target::enabled()
                ->where(function ($q) {
                    $q->where('icmp_enabled', true)
                      ->orWhere(function ($q2) {
                          $q2->where('tcp_enabled', true)->whereNotNull('tcp_port');
                      });
                })
                ->get();

            if ($targets->isEmpty()) {
                $this->info('No enabled targets found.');
                return self::SUCCESS;
            }

            $expectedProbes = $targets->sum(function ($t) {
                return ($t->icmp_enabled ? 1 : 0) + ($t->tcp_enabled ? 1 : 0);
            });

            $cycle = ProbeCycle::create([
                'started_at' => now(),
                'target_count' => $targets->count(),
                'expected_probe_count' => $expectedProbes,
                'successful_probe_count' => 0,
                'failed_probe_count' => 0,
                'status' => 'running',
            ]);

            foreach ($targets as $target) {
                ProbeTarget::dispatch($target->id, $cycle->id)
                    ->onQueue('probes');
            }

            $this->info("Probe cycle #{$cycle->id} started for {$targets->count()} targets ({$expectedProbes} probes).");

        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
