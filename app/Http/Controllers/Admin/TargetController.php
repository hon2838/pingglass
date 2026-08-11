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
use Illuminate\Validation\Rule;
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

        if (
            $target->host !== $data['host']
            || $target->icmp_enabled !== (bool) $data['icmp_enabled']
            || $target->tcp_enabled !== (bool) $data['tcp_enabled']
            || $target->tcp_port !== ($data['tcp_port'] ?? null)
            || $target->probe_interval_seconds !== ($data['probe_interval_seconds'] ?? null)
            || $target->is_enabled !== (bool) $data['is_enabled']
        ) {
            $data['next_probe_at'] = null;
            $data['active_probe_cycle_id'] = null;
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

        $values = [$field => !$target->$field];
        if ($field === 'is_enabled') {
            $values['next_probe_at'] = $values[$field] ? null : $target->next_probe_at;
            $values['active_probe_cycle_id'] = null;
        }
        $target->update($values);
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

    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:targets,id',
        ]);

        $count = Target::whereIn('id', $request->ids)->count();
        AuditLog::log('target.batch_deleted', null, ['ids' => $request->ids, 'count' => $count]);
        Target::whereIn('id', $request->ids)->delete();

        return back()->with('success', "Deleted {$count} targets.");
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'category_id' => 'required|exists:categories,id',
            'is_public' => 'boolean',
            'is_enabled' => 'boolean',
            'icmp_enabled' => 'boolean',
            'tcp_enabled' => 'boolean',
            'show_host_publicly' => 'boolean',
        ]);

        $file = $request->file('file');
        $lines = array_filter(explode("\n", file_get_contents($file->getRealPath())));
        $categoryId = $request->input('category_id');
        $isPublic = $request->boolean('is_public', true);
        $isEnabled = $request->boolean('is_enabled', true);
        $icmpEnabled = $request->boolean('icmp_enabled', true);
        $tcpEnabled = $request->boolean('tcp_enabled', false);
        $showHost = $request->boolean('show_host_publicly', false);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || $i === 0 && str_starts_with(strtolower($line), 'name')) continue;

            $parts = array_map('trim', str_getcsv($line));
            if (count($parts) < 2) {
                $errors[] = "Line " . ($i + 1) . ": needs at least name,host";
                $skipped++;
                continue;
            }

            $name = $parts[0];
            $host = $parts[1];
            $tcpPort = $parts[2] ?? null;
            $slug = $parts[3] ?? null;

            if (empty($name) || empty($host)) {
                $errors[] = "Line " . ($i + 1) . ": name and host required";
                $skipped++;
                continue;
            }

            // Auto-generate slug if not provided
            if (empty($slug)) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]+/u', '-', $name));
                $slug = trim($slug, '-');
                // Ensure uniqueness
                $baseSlug = $slug;
                $counter = 1;
                while (Target::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
            }

            // SSRF validation
            try {
                SsrfProtection::validateHost($host);
            } catch (ValidationException $e) {
                $errors[] = "Line " . ($i + 1) . ": {$host} - unsafe host";
                $skipped++;
                continue;
            }

            $targetTcpPort = $tcpPort ? (int) $tcpPort : null;
            $targetTcpEnabled = $tcpEnabled && $targetTcpPort;

            try {
                Target::create([
                    'category_id' => $categoryId,
                    'name' => $name,
                    'slug' => $slug,
                    'host' => $host,
                    'show_host_publicly' => $showHost,
                    'is_public' => $isPublic,
                    'is_enabled' => $isEnabled,
                    'icmp_enabled' => $icmpEnabled,
                    'tcp_enabled' => $targetTcpEnabled,
                    'tcp_port' => $targetTcpPort,
                    'sort_order' => 0,
                ]);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Line " . ($i + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }

        $msg = "Imported {$imported} targets.";
        if ($skipped > 0) $msg .= " Skipped {$skipped}.";

        AuditLog::log('target.imported', null, ['imported' => $imported, 'skipped' => $skipped]);

        if (!empty($errors) && $imported === 0) {
            return back()->with('error', implode(' ', array_slice($errors, 0, 5)));
        }

        return back()->with('success', $msg);
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
            'probe_interval_seconds' => ['nullable', 'integer', Rule::in([60, 120, 300, 600, 900, 1800, 3600])],
            'sort_order' => 'integer|min:0',
        ];
    }
}
