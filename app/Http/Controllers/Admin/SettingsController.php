<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitorSetting;
use App\Services\Monitoring\SettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(SettingsService $settings)
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => [
                // Probe interval is fixed at 60 seconds for V1
                'probe_interval' => 60,
                'icmp_samples' => $settings->icmpSamples(),
                'tcp_samples' => $settings->tcpSamples(),
                'icmp_timeout' => $settings->icmpTimeout(),
                'tcp_timeout' => $settings->tcpTimeout(),
                'raw_retention_days' => $settings->rawRetentionDays(),
                'rollup5m_retention_days' => $settings->rollup5mRetentionDays(),
                'down_confirmation_cycles' => $settings->downConfirmationCycles(),
                'recovery_confirmation_cycles' => $settings->recoveryConfirmationCycles(),
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings)
    {
        $data = $request->validate([
            'icmp_samples' => 'required|integer|min:1|max:100',
            'tcp_samples' => 'required|integer|min:1|max:100',
            'icmp_timeout' => 'required|integer|min:100|max:30000',
            'tcp_timeout' => 'required|integer|min:100|max:30000',
            'raw_retention_days' => 'required|integer|min:1|max:365',
            'rollup5m_retention_days' => 'required|integer|min:1|max:3650',
            'down_confirmation_cycles' => 'required|integer|min:1|max:10',
            'recovery_confirmation_cycles' => 'required|integer|min:1|max:10',
        ]);

        foreach ($data as $key => $value) {
            MonitorSetting::setValue($key, $value, 'int');
        }

        $settings->refresh();

        return back()->with('success', 'Settings saved. Changes will take effect on the next probe cycle.');
    }
}
