<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Incident;
use App\Models\ProbeCycle;
use App\Models\Target;
use App\Models\TargetState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $targets = Target::enabled()->count();
        $states = TargetState::selectRaw("
            SUM(CASE WHEN overall_status = 'online' THEN 1 ELSE 0 END) as online,
            SUM(CASE WHEN overall_status = 'degraded' THEN 1 ELSE 0 END) as degraded,
            SUM(CASE WHEN overall_status = 'down' THEN 1 ELSE 0 END) as down,
            SUM(CASE WHEN overall_status = 'unknown' THEN 1 ELSE 0 END) as unknown
        ")->first();

        $lastCycle = ProbeCycle::latest('started_at')->first();
        $recentIncidents = Incident::with('target')
            ->open()
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $queueSize = 0;
        try {
            $queueSize = Redis::llen('queues:probes');
        } catch (\Exception) {}

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'targets' => $targets,
                'online' => $states->online ?? 0,
                'degraded' => $states->degraded ?? 0,
                'down' => $states->down ?? 0,
                'unknown' => $states->unknown ?? 0,
            ],
            'lastCycle' => $lastCycle ? [
                'id' => $lastCycle->id,
                'started_at' => $lastCycle->started_at->toISOString(),
                'completed_at' => $lastCycle->completed_at?->toISOString(),
                'duration' => $lastCycle->duration_seconds,
                'status' => $lastCycle->status,
                'target_count' => $lastCycle->target_count,
                'successful' => $lastCycle->successful_probe_count,
                'failed' => $lastCycle->failed_probe_count,
            ] : null,
            'recentIncidents' => $recentIncidents->map(fn($i) => [
                'id' => $i->id,
                'target_name' => $i->target->name,
                'type' => $i->type,
                'protocol' => $i->protocol,
                'started_at' => $i->started_at->toISOString(),
                'duration_human' => $i->duration_human,
            ]),
            'queueSize' => $queueSize,
        ]);
    }
}
