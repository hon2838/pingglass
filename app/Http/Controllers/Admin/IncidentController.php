<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'open');

        $query = Incident::with('target')->orderByDesc('started_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $incidents = $query->paginate(25);

        return Inertia::render('Admin/Incidents/Index', [
            'incidents' => $incidents->through(fn($i) => [
                'id' => $i->id,
                'target_name' => $i->target->name,
                'target_id' => $i->target_id,
                'type' => $i->type,
                'protocol' => $i->protocol,
                'started_at' => $i->started_at->toISOString(),
                'ended_at' => $i->ended_at?->toISOString(),
                'status' => $i->status,
                'initial_reason' => $i->initial_reason,
                'last_reason' => $i->last_reason,
                'duration_human' => $i->duration_human,
            ]),
            'currentStatus' => $status,
        ]);
    }

    public function close(Incident $incident)
    {
        $incident->close('Manually closed');
        return back()->with('success', 'Incident closed.');
    }
}
