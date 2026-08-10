<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Target;
use App\Services\Monitoring\Drivers\FpingDriver;
use App\Services\Monitoring\Drivers\TcpConnectDriver;
use App\Services\Monitoring\SsrfProtection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TargetController extends Controller
{
    public function index(Request $request)
    {
        $query = Target::with(['category', 'state']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('host', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $targets = $query->ordered()->paginate(25);
        $categories = Category::ordered()->get();

        return Inertia::render('Admin/Targets/Index', [
            'targets' => $targets,
            'categories' => $categories,
            'filters' => $request->only(['search', 'category_id']),
        ]);
    }

    public function create()
    {
        $categories = Category::ordered()->get();

        return Inertia::render('Admin/Targets/Create', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validationRules());

        $this->validateProbeConfig($data);

        // SSRF protection
        try {
            SsrfProtection::validateHost($data['host']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        if (!$data['tcp_enabled']) {
            $data['tcp_port'] = null;
        }

        $target = Target::create($data);
        AuditLog::log('target.created', $target, $data);

        return redirect()->route('admin.targets.index')
            ->with('success', 'Target created.');
    }

    public function edit(Target $target)
    {
        $categories = Category::ordered()->get();

        return Inertia::render('Admin/Targets/Edit', [
            'target' => $target,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Target $target)
    {
        $data = $request->validate($this->validationRules($target->id));

        $this->validateProbeConfig($data);

        // SSRF protection
        try {
            SsrfProtection::validateHost($data['host']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        if (!$data['tcp_enabled']) {
            $data['tcp_port'] = null;
        }

        $target->update($data);
        AuditLog::log('target.updated', $target, $data);

        return redirect()->route('admin.targets.index')
            ->with('success', 'Target updated.');
    }

    public function destroy(Target $target)
    {
        AuditLog::log('target.deleted', $target);
        $target->delete();

        return redirect()->route('admin.targets.index')
            ->with('success', 'Target deleted.');
    }

    public function toggle(Target $target, Request $request)
    {
        $field = $request->input('field');
        if (!in_array($field, ['is_public', 'is_enabled', 'show_host_publicly'])) {
            return back()->with('error', 'Invalid field.');
        }

        $target->update([$field => !$target->$field]);
        AuditLog::log("target.toggled", $target, [$field => $target->$field]);

        return back()->with('success', 'Target updated.');
    }

    /**
     * Test connection to a target using draft fields (no existing model required).
     * Accepts POST with host, icmp_enabled, tcp_enabled, tcp_port.
     */
    public function test(Request $request, FpingDriver $fping, TcpConnectDriver $tcp)
    {
        $data = $request->validate([
            'host' => 'required|string|max:255',
            'icmp_enabled' => 'boolean',
            'tcp_enabled' => 'boolean',
            'tcp_port' => 'nullable|integer|min:1|max:65535',
        ]);

        // SSRF protection
        try {
            SsrfProtection::validateHost($data['host']);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Create a temporary target-like object for probing
        $tempTarget = new Target([
            'host' => $data['host'],
            'icmp_enabled' => $data['icmp_enabled'] ?? true,
            'tcp_enabled' => $data['tcp_enabled'] ?? false,
            'tcp_port' => $data['tcp_port'] ?? null,
        ]);

        $results = [];

        if ($tempTarget->icmp_enabled) {
            $result = $fping->probe($tempTarget, 5);
            $results['icmp'] = [
                'status' => $result->status === 'error' ? 'error' : ($result->lossPercent >= 100 ? 'unreachable' : 'reachable'),
                'median_ms' => $result->medianMs,
                'loss_percent' => $result->lossPercent,
                'error' => $result->error,
            ];
        }

        if ($tempTarget->tcp_enabled && $tempTarget->tcp_port) {
            $result = $tcp->probe($tempTarget, 5);
            $results['tcp'] = [
                'status' => $result->status === 'error' ? 'error' : ($result->lossPercent >= 100 ? 'unreachable' : 'reachable'),
                'median_ms' => $result->medianMs,
                'loss_percent' => $result->lossPercent,
                'error' => $result->error,
            ];
        }

        return response()->json($results);
    }

    private function validateProbeConfig(array $data): void
    {
        if ($data['tcp_enabled'] && empty($data['tcp_port'])) {
            throw ValidationException::withMessages([
                'tcp_port' => 'TCP port is required when TCP monitoring is enabled.',
            ]);
        }

        if (!$data['icmp_enabled'] && !$data['tcp_enabled']) {
            throw ValidationException::withMessages([
                'icmp_enabled' => 'At least one monitoring method must be enabled.',
            ]);
        }
    }

    private function validationRules(?int $exceptId = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:targets,slug,' . $exceptId,
            'category_id' => 'required|exists:categories,id',
            'host' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'show_host_publicly' => 'boolean',
            'is_public' => 'boolean',
            'is_enabled' => 'boolean',
            'icmp_enabled' => 'boolean',
            'tcp_enabled' => 'boolean',
            'tcp_port' => 'nullable|integer|min:1|max:65535',
            'loss_threshold_percent' => 'nullable|numeric|min:0|max:100',
            'latency_threshold_ms' => 'nullable|numeric|min:0|max:10000',
            'sort_order' => 'integer|min:0',
        ];
    }
}
