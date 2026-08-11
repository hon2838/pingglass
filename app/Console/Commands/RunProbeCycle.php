<?php

namespace App\Console\Commands;

use App\Jobs\ProbeTargetsChunk;
use App\Models\ProbeCycle;
use App\Models\Target;
use App\Services\Monitoring\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunProbeCycle extends Command
{
    protected $signature = 'pingglass:probe-cycle';
    protected $description = 'Dispatch bounded probe jobs for targets that are due';

    public function handle(SettingsService $settings): int
    {
        $lock = Cache::lock('probe-cycle-dispatch-lock', 55);
        if (!$lock->get()) {
            $this->warn('Probe dispatch lock held, skipping.');
            return self::SUCCESS;
        }

        try {
            $now = now();
            $targets = Target::enabled()
                ->where(function ($query) {
                    $query->where('icmp_enabled', true)
                        ->orWhere(function ($tcp) {
                            $tcp->where('tcp_enabled', true)->whereNotNull('tcp_port');
                        });
                })
                ->where(function ($query) use ($now) {
                    $query->whereNull('next_probe_at')->orWhere('next_probe_at', '<=', $now);
                })
                ->whereNull('active_probe_cycle_id')
                ->get([
                    'id', 'icmp_enabled', 'tcp_enabled', 'tcp_port',
                    'probe_interval_seconds',
                ]);

            if ($targets->isEmpty()) {
                $this->info('No targets are due.');
                return self::SUCCESS;
            }

            $expectedProbes = $targets->sum(fn(Target $target) =>
                ($target->icmp_enabled ? 1 : 0)
                + ($target->tcp_enabled && $target->tcp_port ? 1 : 0)
            );

            $cycle = ProbeCycle::create([
                'started_at' => $now,
                'target_count' => $targets->count(),
                'expected_probe_count' => $expectedProbes,
                'successful_probe_count' => 0,
                'failed_probe_count' => 0,
                'status' => 'running',
            ]);

            $defaultInterval = max(60, $settings->probeInterval());
            $targets->groupBy(fn(Target $target) => max(60, $target->probe_interval_seconds ?: $defaultInterval))
                ->each(function ($group, $interval) use ($now, $cycle) {
                    foreach ($group->pluck('id')->chunk(500) as $ids) {
                        Target::whereIn('id', $ids)->update([
                            'next_probe_at' => $now->copy()->addSeconds((int) $interval),
                            'active_probe_cycle_id' => $cycle->id,
                        ]);
                    }
                });

            $chunkSize = min(250, max(25, (int) config('pingglass.probe_chunk_size', 100)));
            foreach ($targets->pluck('id')->chunk($chunkSize) as $targetIds) {
                ProbeTargetsChunk::dispatch($targetIds->values()->all(), $cycle->id);
            }

            $jobCount = (int) ceil($targets->count() / $chunkSize);
            $this->info(
                "Cycle #{$cycle->id}: {$targets->count()} due targets, "
                . "{$expectedProbes} protocol probes, {$jobCount} chunk jobs."
            );
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
