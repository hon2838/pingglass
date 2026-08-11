<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ScopeMeasurementRollup;
use App\Models\Target;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MonitorController extends Controller
{
    public function index()
    {
        $categories = Category::public()
            ->ordered()
            ->get();

        $scopeMetrics = ScopeMeasurementRollup::where('granularity', '5m')
            ->where('period_start', '>=', now()->subDay())
            ->whereIn('scope_key', $categories->pluck('id')->map(fn($id) => "category:{$id}")->push('global'))
            ->orderBy('period_start')
            ->get()
            ->groupBy('scope_key');

        // For each category, load only the first 5 targets + a count of total
        $categories->each(function ($cat) {
            $cat->setRelation('targets', $cat->targets()
                ->public()
                ->ordered()
                ->with('state')
                ->limit(5)
                ->get());
            $cat->total_targets = $cat->targets()->public()->count();
        });

        $overallStatus = $this->calculateOverallStatus();

        return Inertia::render('Public/Dashboard', [
            'categories' => $categories->map(fn($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'total_targets' => $cat->total_targets,
                'latency_metrics' => $this->formatScopeMetrics($scopeMetrics->get("category:{$cat->id}", collect())),
                'targets' => $cat->targets->map(fn($t) => array_merge($t->toArrayPublic(), [
                    'state' => $t->state ? [
                        'overall_status' => $t->state->overall_status,
                        'icmp_status' => $t->state->icmp_status,
                        'icmp_latency_ms' => $t->state->icmp_latency_ms,
                        'icmp_loss_percent' => $t->state->icmp_loss_percent,
                        'tcp_status' => $t->state->tcp_status,
                        'tcp_latency_ms' => $t->state->tcp_latency_ms,
                        'tcp_loss_percent' => $t->state->tcp_loss_percent,
                        'last_measured_at' => $t->state->last_measured_at?->toISOString(),
                    ] : null,
                ])),
            ]),
            'overallStatus' => $overallStatus,
            'globalLatencyMetrics' => $this->formatScopeMetrics($scopeMetrics->get('global', collect())),
            'lastUpdated' => now()->toISOString(),
        ]);
    }

    public function category(Category $category, Request $request)
    {
        if (!$category->is_public || !$category->is_enabled) {
            abort(404);
        }

        $query = Target::where('category_id', $category->id)
            ->public()
            ->ordered()
            ->with('state');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('host', 'like', "%{$search}%");
            });
        }

        $targets = $query->paginate(25);

        $scopeMetrics = ScopeMeasurementRollup::where('scope_key', "category:{$category->id}")
            ->where('granularity', '5m')
            ->where('period_start', '>=', now()->subDay())
            ->orderBy('period_start')
            ->get();

        $mappedData = $targets->getCollection()->map(fn($t) => array_merge($t->toArrayPublic(), [
            'state' => $t->state ? [
                'overall_status' => $t->state->overall_status,
                'icmp_status' => $t->state->icmp_status,
                'icmp_latency_ms' => $t->state->icmp_latency_ms,
                'icmp_loss_percent' => $t->state->icmp_loss_percent,
                'tcp_status' => $t->state->tcp_status,
                'tcp_latency_ms' => $t->state->tcp_latency_ms,
                'tcp_loss_percent' => $t->state->tcp_loss_percent,
                'last_measured_at' => $t->state->last_measured_at?->toISOString(),
            ] : null,
        ]))->values()->all();

        return Inertia::render('Public/Category', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
            ],
            'filters' => $request->only(['search']),
            'latencyMetrics' => $this->formatScopeMetrics($scopeMetrics),
            'targets' => [
                'data' => $mappedData,
                'links' => $targets->linkCollection()->toArray(),
                'current_page' => $targets->currentPage(),
                'last_page' => $targets->lastPage(),
                'total' => $targets->total(),
            ],
        ]);
    }

    public function apiStatus(): JsonResponse
    {
        $categories = Category::public()->ordered()->get();

        $categories->each(function ($cat) {
            $cat->setRelation('targets', $cat->targets()
                ->public()
                ->ordered()
                ->with('state')
                ->limit(5)
                ->get());
        });

        return response()->json([
            'overall_status' => $this->calculateOverallStatus(),
            'last_updated' => now()->toISOString(),
            'categories' => $categories->map(fn($cat) => [
                'name' => $cat->name,
                'slug' => $cat->slug,
                'targets' => $cat->targets->map(fn($t) => $t->toArrayPublic()),
            ]),
        ]);
    }

    public function apiCategories(): JsonResponse
    {
        $categories = Category::public()->ordered()->get();
        return response()->json($categories);
    }

    private function calculateOverallStatus(): string
    {
        $counts = Target::query()
            ->join('categories', 'categories.id', '=', 'targets.category_id')
            ->leftJoin('target_states', 'target_states.target_id', '=', 'targets.id')
            ->where('targets.is_public', true)
            ->where('targets.is_enabled', true)
            ->where('categories.is_public', true)
            ->where('categories.is_enabled', true)
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN target_states.overall_status = 'online' THEN 1 ELSE 0 END) as online")
            ->selectRaw("SUM(CASE WHEN target_states.overall_status = 'degraded' THEN 1 ELSE 0 END) as degraded")
            ->selectRaw("SUM(CASE WHEN target_states.overall_status = 'down' THEN 1 ELSE 0 END) as down_count")
            ->selectRaw("SUM(CASE WHEN target_states.overall_status IS NULL OR target_states.overall_status = 'unknown' THEN 1 ELSE 0 END) as unknown_count")
            ->first();

        $total = (int) ($counts->total ?? 0);
        if ($total === 0 || (int) $counts->unknown_count === $total) return 'unknown';
        if ((int) $counts->down_count === $total) return 'down';
        if ((int) $counts->down_count > 0 || (int) $counts->degraded > 0 || (int) $counts->unknown_count > 0) {
            return 'degraded';
        }

        return 'operational';
    }

    private function formatScopeMetrics($rollups): array
    {
        $series = ['icmp' => [], 'tcp' => []];
        foreach ($rollups as $rollup) {
            $series[$rollup->protocol][] = [
                'time' => $rollup->period_start->toISOString(),
                'avg' => $rollup->avg_ms,
                'loss' => $rollup->loss_percent,
                'targets' => $rollup->target_count,
            ];
        }

        return $series;
    }
}
