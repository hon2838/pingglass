<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProbeCycle;
use App\Services\Monitoring\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;

class HealthController extends Controller
{
    public function index(SettingsService $settings)
    {
        $checks = [];

        // Database
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['status' => 'ok', 'message' => 'Connected'];
        } catch (\Exception $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Redis
        try {
            Redis::ping();
            $checks['redis'] = ['status' => 'ok', 'message' => 'Connected'];
        } catch (\Exception $e) {
            $checks['redis'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Queue — check the actual configured queue driver
        try {
            if (config('queue.default') === 'database') {
                $queueSize = DB::table('jobs')->count();
            } else {
                $queueSize = Redis::llen('queues:probes');
            }
            $checks['queue'] = [
                'status' => $queueSize > 100 ? 'warning' : 'ok',
                'message' => "{$queueSize} jobs pending",
                'pending' => $queueSize,
            ];
        } catch (\Exception) {
            $checks['queue'] = ['status' => 'unknown', 'message' => 'Unable to check'];
        }

        // Worker heartbeat — check if probe jobs are completing
        try {
            $recentComplete = ProbeCycle::where('status', 'completed')
                ->where('completed_at', '>=', now()->subMinutes(5))
                ->exists();
            $checks['worker'] = [
                'status' => $recentComplete ? 'ok' : 'warning',
                'message' => $recentComplete
                    ? 'Probe workers active (recent cycle completed)'
                    : 'No recently completed cycles — workers may be stopped',
            ];
        } catch (\Exception) {
            $checks['worker'] = ['status' => 'unknown', 'message' => 'Unable to check'];
        }

        // Failed jobs
        try {
            $failedCount = DB::table('failed_jobs')->count();
            $checks['failed_jobs'] = [
                'status' => $failedCount > 0 ? 'warning' : 'ok',
                'message' => "{$failedCount} failed jobs",
                'count' => $failedCount,
            ];
        } catch (\Exception) {
            $checks['failed_jobs'] = ['status' => 'unknown', 'message' => 'Failed jobs table not found'];
        }

        // fping — test existence, executability, and functionality
        $fpingPath = config('pingglass.fping_path', '/usr/bin/fping');
        $fpingExists = file_exists($fpingPath);
        $fpingExecutable = $fpingExists && is_executable($fpingPath);
        $fpingWorks = false;

        if ($fpingExecutable) {
            try {
                $result = Process::timeout(5)->run([$fpingPath, '127.0.0.1', '-c', '1', '-t', '500']);
                $fpingWorks = true; // Any output means the binary works
            } catch (\Exception) {}
        }

        $checks['fping'] = [
            'status' => $fpingWorks ? 'ok' : ($fpingExecutable ? 'warning' : 'error'),
            'message' => $fpingWorks
                ? "Working at {$fpingPath}"
                : ($fpingExecutable
                    ? "Found but test failed at {$fpingPath}"
                    : ($fpingExists
                        ? "Not executable at {$fpingPath}"
                        : "Not found at {$fpingPath}")),
            'path' => $fpingPath,
        ];

        // TCP probe — actually test a socket connection
        $tcpTestHost = '8.8.8.8';
        $tcpTestPort = 53;
        $tcpOk = false;
        try {
            $socket = @fsockopen($tcpTestHost, $tcpTestPort, $errno, $errstr, 2);
            if ($socket) {
                fclose($socket);
                $tcpOk = true;
            }
        } catch (\Exception) {}

        $checks['tcp_probe'] = [
            'status' => $tcpOk ? 'ok' : 'warning',
            'message' => $tcpOk
                ? "PHP socket probing works (tested {$tcpTestHost}:{$tcpTestPort})"
                : "Could not connect to {$tcpTestHost}:{$tcpTestPort} — firewall or socket issue",
        ];

        // Scheduler (check last cycle)
        $lastCycle = ProbeCycle::latest('started_at')->first();
        $schedulerFresh = $lastCycle && $lastCycle->started_at->diffInSeconds(now()) < 180;

        $checks['scheduler'] = [
            'status' => $lastCycle ? ($schedulerFresh ? 'ok' : 'warning') : 'unknown',
            'message' => $lastCycle
                ? "Last cycle #{$lastCycle->id} at {$lastCycle->started_at->format('H:i:s')} ({$lastCycle->started_at->diffForHumans()})"
                : 'No cycles recorded yet',
            'last_cycle_at' => $lastCycle?->started_at?->toISOString(),
        ];

        // Last completed cycle
        $lastCompleted = ProbeCycle::where('status', 'completed')
            ->latest('completed_at')
            ->first();

        $checks['last_completed_cycle'] = [
            'status' => $lastCompleted ? 'ok' : 'unknown',
            'message' => $lastCompleted
                ? "Cycle #{$lastCompleted->id}: {$lastCompleted->successful_probe_count}/{$lastCompleted->expected_probe_count} successful, "
                    . ($lastCompleted->duration_seconds ?? '?') . 's'
                : 'No completed cycles yet',
        ];

        // Probe settings
        $checks['probe_config'] = [
            'status' => 'ok',
            'message' => "Interval: {$settings->probeInterval()}s, ICMP: {$settings->icmpSamples()} samples/{$settings->icmpTimeout()}ms, TCP: {$settings->tcpSamples()} samples/{$settings->tcpTimeout()}ms",
        ];

        return Inertia::render('Admin/Health/Index', [
            'checks' => $checks,
        ]);
    }
}
