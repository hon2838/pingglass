<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
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

        $overallStatus = $this->calculateOverallStatus($categories);

        return Inertia::render('Public/Dashboard', [
            'categories' => $categories->map(fn($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'total_targets' => $cat->total_targets,
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
            'overall_status' => $this->calculateOverallStatus($categories),
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

    private function calculateOverallStatus($categories): string
    {
        $hasDown = false;
        $hasDegraded = false;

        foreach ($categories as $cat) {
            foreach ($cat->targets as $target) {
                $status = $target->state?->overall_status ?? 'unknown';
                if ($status === 'down') $hasDown = true;
                if ($status === 'degraded') $hasDegraded = true;
            }
        }

        if ($hasDown) return 'degraded';
        if ($hasDegraded) return 'degraded';
        return 'operational';
    }
}
