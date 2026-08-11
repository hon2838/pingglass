<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitorSetting;
use App\Models\Target;
use App\Services\Monitoring\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(SettingsService $settings)
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => [
                'probe_interval' => $settings->probeInterval(),
                'icmp_samples' => $settings->icmpSamples(),
                'tcp_samples' => $settings->tcpSamples(),
                'icmp_timeout' => $settings->icmpTimeout(),
                'tcp_timeout' => $settings->tcpTimeout(),
                'loss_threshold_percent' => $settings->lossThresholdPercent(),
                'latency_threshold_ms' => $settings->latencyThresholdMs(),
                'raw_retention_days' => $settings->rawRetentionDays(),
                'rollup5m_retention_days' => $settings->rollup5mRetentionDays(),
                'down_confirmation_cycles' => $settings->downConfirmationCycles(),
                'recovery_confirmation_cycles' => $settings->recoveryConfirmationCycles(),
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings)
    {
        $previousProbeInterval = $settings->probeInterval();
        $data = $request->validate([
            'probe_interval' => ['required', 'integer', Rule::in([60, 120, 300, 600, 900, 1800, 3600])],
            'icmp_samples' => 'required|integer|min:1|max:100',
            'tcp_samples' => 'required|integer|min:1|max:100',
            'icmp_timeout' => 'required|integer|min:100|max:30000',
            'tcp_timeout' => 'required|integer|min:100|max:30000',
            'loss_threshold_percent' => 'required|numeric|min:0|max:100',
            'latency_threshold_ms' => 'required|numeric|min:0|max:10000',
            'raw_retention_days' => 'required|integer|min:1|max:365',
            'rollup5m_retention_days' => 'required|integer|min:1|max:3650',
            'down_confirmation_cycles' => 'required|integer|min:1|max:10',
            'recovery_confirmation_cycles' => 'required|integer|min:1|max:10',
        ]);

        foreach ($data as $key => $value) {
            $type = in_array($key, ['loss_threshold_percent', 'latency_threshold_ms'], true) ? 'float' : 'int';
            MonitorSetting::setValue($key, $value, $type);
        }

        $settings->refresh();

        if ((int) $data['probe_interval'] !== $previousProbeInterval) {
            Target::whereNull('probe_interval_seconds')->update(['next_probe_at' => null]);
        }

        return back()->with('success', 'Settings saved. Changes will take effect on the next probe cycle.');
    }
}
